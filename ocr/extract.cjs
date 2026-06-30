const fs = require('fs');
const { PDFParse } = require('pdf-parse');
const Tesseract = require('tesseract.js');

const filePath = process.argv[2];

if (!filePath || !fs.existsSync(filePath)) {
    process.stdout.write(JSON.stringify({ error: 'File not found: ' + filePath }));
    process.exit(1);
}

async function parsePdfText(buffer) {
    const parser = new PDFParse({ data: buffer });
    const result = await parser.getText();
    return result.text || '';
}

async function extractKeySection(text, sectionNames, nextSections) {
    const lines = text.split('\n');
    let capturing = false;
    let result = [];

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim().toLowerCase();

        if (sectionNames.some(s => line === s || line.startsWith(s))) {
            capturing = true;
            continue;
        }

        if (capturing && nextSections.some(s => line === s || line.startsWith(s))) {
            break;
        }

        if (capturing && lines[i].trim()) {
            result.push(lines[i].trim());
        }
    }

    return result.join(' ').substring(0, 2000);
}

async function extractFromDigitalPdf(text) {
    const title = text.split('\n').slice(0, 5).join(' ').trim();

    const abstract = await extractKeySection(text,
        ['abstract'],
        ['introduction', 'chapter 1', 'i.', '1.']
    );

    const introduction = await extractKeySection(text,
        ['introduction', 'chapter 1', 'i. introduction'],
        ['review', 'chapter 2', 'ii.', '2.', 'methodology']
    );

    const keywords = await extractKeySection(text,
        ['keywords', 'key words'],
        ['abstract', 'introduction', 'chapter']
    );

    const conclusion = await extractKeySection(text,
        ['conclusion', 'conclusions', 'summary', 'v. conclusion'],
        ['recommendation', 'references', 'bibliography', 'appendix']
    );

    return { title, abstract, introduction, keywords, conclusion, method: 'digital' };
}

async function extractFromScannedPdf(filePath) {
    const { data: { text } } = await Tesseract.recognize(filePath, 'eng', {
        logger: () => {}
    });

    const title = text.split('\n').slice(0, 5).join(' ').trim();

    const abstract = await extractKeySection(text,
        ['abstract'],
        ['introduction', 'chapter 1']
    );

    const keywords = await extractKeySection(text,
        ['keywords', 'key words'],
        ['abstract', 'introduction']
    );

    const conclusion = await extractKeySection(text,
        ['conclusion', 'conclusions'],
        ['recommendation', 'references', 'bibliography']
    );

    return { title, abstract, introduction: '', keywords, conclusion, method: 'ocr' };
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