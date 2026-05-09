const consoleErrors = [];
const pageErrors = [];

export function setupConsoleLogging(page) {
    page.on('console', msg => {
        const type = msg.type();
        const text = msg.text();

        if (type === 'error') {
            consoleErrors.push({ type: 'console', message: text });
            console.log(`[Console Error] ${text}`);
        } else if (type === 'warning') {
            console.log(`[Console Warning] ${text}`);
        }
    });

    page.on('pageerror', error => {
        pageErrors.push({ type: 'page', message: error.message, stack: error.stack });
        console.log(`[Page Error] ${error.message}`);
    });
}

export function getConsoleErrors() {
    return [...consoleErrors];
}

export function getPageErrors() {
    return [...pageErrors];
}

export function clearErrors() {
    consoleErrors.length = 0;
    pageErrors.length = 0;
}

export function assertNoConsoleErrors() {
    const errors = getConsoleErrors();
    if (errors.length > 0) {
        throw new Error(
            `Found ${errors.length} console errors:\n` +
            errors.map(e => `  - ${e.type}: ${e.message}`).join('\n')
        );
    }
}

export function assertNoPageErrors() {
    const errors = getPageErrors();
    if (errors.length > 0) {
        throw new Error(
            `Found ${errors.length} page errors:\n` +
            errors.map(e => `  - ${e.message}`).join('\n')
        );
    }
}
