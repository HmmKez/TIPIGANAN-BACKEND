const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { PDFParse } = require('pdf-parse');

const filePath = process.argv[2];

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

// ---------------------------------------------------------------------------
// The item's year.
//
// A thesis or collection item states its year in its front matter - the cover
// ("December 2023"), an approval sheet, "A.Y. 2023-2024", "(c) 2021", or the
// year alone on a line. Only that front matter is searched: past the abstract,
// table of contents or first chapter, a 4-digit number is far more likely to
// be about something else ("in March 2020, ...") than the item's own year.
//
// Turnitin-wrapped PDFs open with a report whose own dates ("Submission Date
// Apr 22, 2026, 9:21 AM GMT+8") are not the item's year, so report and
// timestamp lines are skipped.
// ---------------------------------------------------------------------------
const MONTHS = 'january|february|march|april|may|june|july|august|september|october|november|december' +
    '|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec';

const NOISE_LINE = new RegExp([
    'submission (id|date)', 'download date', 'report date', 'trn:oid', 'file size',
    'page \\d+ of \\d+', 'turnitin', 'similarity', 'ai writing', 'ai detection',
    '\\b\\d{1,2}:\\d{2}\\s*(am|pm)\\b', '\\bgmt\\b',
].join('|'), 'i');

const FRONT_MATTER_END = ['abstract', 'table of contents', 'contents', 'acknowledgment',
    'acknowledgement', 'chapter 1', 'chapter i', 'chapter one', 'introduction'];

function plausibleYear(y) {
    const n = Number(y);
    return n >= 1950 && n <= new Date().getFullYear() + 1 ? n : null;
}

function extractYear(lines) {
    const candidates = [];
    for (let i = 0; i < lines.length && i < 600; i++) {
        const line = lines[i].trim();
        if (!line) continue;
        // Front matter ends at the first real section heading. Only a short
        // line counts as a heading - "This study's introduction of..." does not.
        if (line.length <= 40 && matchesHeading(line, FRONT_MATTER_END)) break;
        if (NOISE_LINE.test(line)) continue;

        const push = (y, score) => { const n = plausibleYear(y); if (n) candidates.push({ n, score, i }); };
        let m;
        // "A.Y. 2023-2024", "S.Y. 2023 - 2024", "Academic Year 2023–2024": the
        // later year, when the work was completed.
        if ((m = line.match(/\b(a\.?\s?y\.?|s\.?\s?y\.?|academic year|school year)\s*:?\s*((?:19|20)\d{2})\s*[-–—/]\s*((?:19|20)\d{2})\b/i))) push(m[3], 4);
        // "December 2023", "May, 2024", "June 15, 2022".
        const monthYear = new RegExp(`\\b(?:${MONTHS})\\.?,?\\s+(?:\\d{1,2},?\\s+)?((?:19|20)\\d{2})\\b`, 'i');
        if ((m = line.match(monthYear))) push(m[1], 5);
        // "© 2021", "Copyright 2021".
        if ((m = line.match(/(?:©|\(c\)|copyright)\s*((?:19|20)\d{2})\b/i))) push(m[1], 4);
        // The year alone on its own line, as on many covers.
        if ((m = line.match(/^((?:19|20)\d{2})$/))) push(m[1], 3);
    }
    if (!candidates.length) return '';
    // Strongest form wins; among equals, the earliest (the cover comes first).
    candidates.sort((a, b) => b.score - a.score || a.i - b.i);
    return String(candidates[0].n);
}

async function extractFromDigitalPdf(text) {
    const lines = text.split('\n');
    const title = extractTitle(lines);
    const year = extractYear(lines);

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

    return { title, year, abstract, introduction, keywords, conclusion, method: 'digital' };
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
    const year = extractYear(lines);
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
        title, year, abstract, introduction: '', keywords, conclusion, method: 'ocr',
        pagesProcessed, totalPages,
    };
}

async function run() {
    if (!filePath || !fs.existsSync(filePath)) {
        process.stdout.write(JSON.stringify({ error: 'File not found: ' + filePath }));
        process.exit(1);
    }

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

// Run only when invoked as a script, so the year rules can be tested directly.
if (require.main === module) {
    run();
}

module.exports = { extractYear };