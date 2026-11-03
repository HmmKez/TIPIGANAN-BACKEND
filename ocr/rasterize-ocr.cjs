// Runs in its own process, spawned by extract.cjs, deliberately.
//
// pdf-parse and pdf-to-img each bundle a different pdfjs-dist version.
// pdfjs-dist registers its "fake worker" (no real Worker threads in
// Node) globally the first time it's required in a process; if
// pdf-parse's copy loads first (extract.cjs always runs it to check for
// a text layer), pdf-to-img's copy then finds a stale worker already
// registered and throws "API version X does not match Worker version Y"
// instead of rendering anything. Keeping pdf-to-img in a fresh process
// sidesteps that entirely.
const fs = require('fs');
const path = require('path');
const Tesseract = require('tesseract.js');

const filePath = process.argv[2];

// Where Tesseract keeps the language file it downloads on first use. By default
// that is whichever folder this runs from, which the web server's user may not be
// allowed to write to on a real server, so OCR would fail there and nowhere else.
const CACHE_PATH = path.join(__dirname, '..', 'storage', 'app', 'ocr-cache');

// Old scanned theses can run 60-150+ pages. The sections extract.cjs
// actually looks for only ever live at the front (title/abstract/
// keywords/intro) or the back (conclusion), so only a capped window at
// each end is rasterized+OCR'd rather than the whole document.
const FRONT_PAGE_CAP = 12;
const BACK_PAGE_CAP = 8;

function pickPageNumbers(totalPages) {
    if (totalPages <= FRONT_PAGE_CAP + BACK_PAGE_CAP) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
    }
    const front = Array.from({ length: FRONT_PAGE_CAP }, (_, i) => i + 1);
    const back = Array.from({ length: BACK_PAGE_CAP }, (_, i) => totalPages - BACK_PAGE_CAP + 1 + i);
    return [...front, ...back];
}

async function run() {
    fs.mkdirSync(CACHE_PATH, { recursive: true });
    const { pdf } = await import('pdf-to-img');
    const document = await pdf(filePath, { scale: 2 });
    const pageNumbers = pickPageNumbers(document.length);

    let text = '';
    for (const pageNum of pageNumbers) {
        const image = await document.getPage(pageNum);
        const { data: { text: pageText } } = await Tesseract.recognize(image, 'eng', {
            logger: () => {},
            cachePath: CACHE_PATH,
        });
        text += '\n' + pageText;
    }

    process.stdout.write(JSON.stringify({
        text,
        pagesProcessed: pageNumbers.length,
        totalPages: document.length,
    }));
}

run().catch((err) => {
    process.stdout.write(JSON.stringify({ error: err.message }));
    process.exit(1);
});
