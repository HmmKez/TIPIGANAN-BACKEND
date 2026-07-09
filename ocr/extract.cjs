const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { PDFParse } = require('pdf-parse');

const filePath = process.argv[2];

if (!filePath || !fs.existsSync(filePath)) {
    process.stdout.write(JSON.stringify({ error: 'File not found: ' + filePath }));
    process.exit(1);
}

async function parsePdfText(buffer) {
    const parser = new PDFParse({ data: buffer });
    const result = await parser.getText();
    return stripPageMarkers(result.text || '');
}

// pdf-parse inserts a "-- N of M --" separator between every page's text
// regardless of whether that page actually contains any real text. On a
// genuinely scanned multi-page PDF (no real text anywhere), these markers
// alone add up to well over the 100-char "has real text" threshold once a
// document is ~7+ pages long — silently misclassifying it as a digital PDF
// and skipping OCR entirely, with no error and no extracted content. Strip
// them before measuring/using the text at all.
function stripPageMarkers(text) {
    return text.replace(/(^|\n)\s*--\s*\d+\s*of\s*\d+\s*--\s*(?=\n|$)/g, '\n');
}

function normalizeLine(line) {
    return line.trim().toLowerCase()
        .replace(/^[\d]+[\.\)]\s*/, '')
        .replace(/^[ivxlcdm]+[\.\)]\s*/i, '')
        .replace(/:$/, '')
        .trim();
}

function matchesHeading(line, headings) {
    const normalized = normalizeLine(line);
    return headings.some(h => normalized === h || normalized.startsWith(h));
}

function extractInlineValue(line, headings) {
    const lower = line.toLowerCase();
    for (const h of headings) {
        const colonForm = h + ':';
        const idx = lower.indexOf(colonForm);
        if (idx !== -1) {
            const value = line.substring(idx + colonForm.length).trim();
            if (value.length > 0) return value;
        }
    }
    return null;
}

// Skip past Table of Contents so we don't match TOC entries
function skipTableOfContents(lines) {
    const tocMarkers = [
        'table of contents', 'contents', 'list of contents'
    ];

    // Chapter body markers — signals we've left the TOC
    const chapterMarkers = [
        'chapter i', 'chapter 1', 'chapter one',
        'i. introduction', '1. introduction',
        'introduction', 'abstract', 'background of the study',
        'project context'
    ];

    let inToc = false;
    let tocEndIndex = 0;

    for (let i = 0; i < lines.length; i++) {
        const norm = lines[i].trim().toLowerCase();

        if (!inToc && tocMarkers.some(t => norm.includes(t))) {
            inToc = true;
            continue;
        }

        if (inToc) {
            // Look for page numbers (lines ending in digits) — TOC pattern
            const isTocLine = /\d+\s*$/.test(lines[i].trim());

            // Look for a line that signals actual chapter content starting
            const isChapterStart = chapterMarkers.some(c =>
                norm === c || norm.startsWith(c)
            );

            if (isChapterStart && !isTocLine) {
                tocEndIndex = i;
                break;
            }
        }
    }

    return tocEndIndex > 0 ? tocEndIndex : 0;
}

async function extractKeySection(lines, startFrom, sectionNames, nextSections, maxLines = 80) {
    let capturing = false;
    let result = [];
    let lineCount = 0;

    for (let i = startFrom; i < lines.length; i++) {
        const raw = lines[i];
        const trimmed = raw.trim();
        if (!trimmed) continue;

        // Check for inline value (e.g. "Keywords: RFID, ocr")
        if (!capturing) {
            const inline = extractInlineValue(trimmed, sectionNames);
            if (inline && inline.length > 0) {
                return inline.substring(0, 2000);
            }
        }

        if (!capturing && matchesHeading(trimmed, sectionNames)) {
            capturing = true;
            continue;
        }

        if (capturing && matchesHeading(trimmed, nextSections)) {
            break;
        }

        if (capturing) {
            if (lineCount >= maxLines) break;
            result.push(trimmed);
            lineCount++;
        }
    }

    return result.join(' ').substring(0, 2000);
}

function extractTitle(lines) {
    for (const line of lines) {
        const trimmed = line.trim();
        if (trimmed.length > 10 && trimmed.length < 250 && /[a-zA-Z]{4,}/.test(trimmed)) {
            // Skip lines that look like author lists or page numbers
            if (/^\d+$/.test(trimmed)) continue;
            if (trimmed.toLowerCase().startsWith('group')) continue;
            return trimmed;
        }
    }
    return '';
}

async function extractFromDigitalPdf(text) {
    const lines = text.split('\n');
    const title = extractTitle(lines);

    // Find where TOC ends so we start searching from real content
    const contentStart = skipTableOfContents(lines);

    const abstract = await extractKeySection(lines, contentStart,
        ['abstract'],
        ['introduction', 'chapter 1', 'chapter i', 'background',
         'background of the study', 'keywords', 'key words',
         'table of contents', 'acknowledgment'],
        60
    );

    const introduction = await extractKeySection(lines, contentStart,
        ['introduction', 'chapter 1', 'chapter i', 'chapter one',
         'project context', 'background of the study', '1.1 project context'],
        ['review', 'related literature', 'chapter 2', 'chapter ii',
         'methodology', 'theoretical framework', 'technical background',
         'chapter iii', 'chapter 3', '2.'],
        80
    );

    const keywords = await extractKeySection(lines, 0, // keywords can appear before TOC skip
        ['keywords', 'key words', 'index terms'],
        ['abstract', 'introduction', 'chapter', 'background', '1.', 'table'],
        5
    );

    const conclusion = await extractKeySection(lines, contentStart,
        ['conclusion', 'conclusions', 'summary and conclusion',
         'conclusions and recommendations', 'chapter 5', 'chapter v',
         '5. conclusion', 'v. conclusion'],
        ['recommendation', 'recommendations', 'references',
         'bibliography', 'appendix', 'appendices'],
        60
    );

    return { title, abstract, introduction, keywords, conclusion, method: 'digital' };
}

// Tesseract.js has no PDF parser (Leptonica only reads raster images), so a
// scanned/image-only PDF has to be rasterized to page images first. That
// rasterization (rasterize-ocr.cjs) runs in its own child process — see the
// comment in that file for why it can't share this process.
async function extractFromScannedPdf(filePath) {
    const scriptPath = path.join(__dirname, 'rasterize-ocr.cjs');
    const raw = execFileSync('node', [scriptPath, filePath], {
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024,
    });

    const { text = '', error, pagesProcessed = 0, totalPages = 0 } = JSON.parse(raw);
    if (error) {
        throw new Error(`OCR rasterization failed: ${error}`);
    }

    const lines = text.split('\n');
    const title = extractTitle(lines);
    const contentStart = skipTableOfContents(lines);

    const abstract = await extractKeySection(lines, contentStart,
        ['abstract'],
        ['introduction', 'chapter 1', 'keywords', 'key words'],
        60
    );

    const keywords = await extractKeySection(lines, 0,
        ['keywords', 'key words', 'index terms'],
        ['abstract', 'introduction', 'chapter'],
        5
    );

    const conclusion = await extractKeySection(lines, contentStart,
        ['conclusion', 'conclusions', 'summary and conclusion'],
        ['recommendation', 'references', 'bibliography', 'appendix'],
        60
    );

    return {
        title, abstract, introduction: '', keywords, conclusion, method: 'ocr',
        pagesProcessed, totalPages,
    };
}

async function run() {
    try {
        const buffer = fs.readFileSync(filePath);
        const text = await parsePdfText(buffer);
        const hasText = text && text.trim().length > 100;

        let result;
        if (hasText) {
            result = await extractFromDigitalPdf(text);
        } else {
            result = await extractFromScannedPdf(filePath);
        }

        process.stdout.write(JSON.stringify(result));
    } catch (err) {
        process.stdout.write(JSON.stringify({ error: err.message }));
        process.exit(1);
    }
}

run();