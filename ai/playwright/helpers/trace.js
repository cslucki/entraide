import path from 'path';

const TRACE_DIR = 'ai/playwright/test-results';

export function getTracePath(testInfo) {
    const safeName = testInfo.title.replace(/\s+/g, '-').replace(/[^a-zA-Z0-9-]/g, '');
    return path.join(TRACE_DIR, `trace-${safeName}.zip`);
}

export async function saveTrace(context) {
    await context.close();
}

export async function saveTraceOnFailure(context, testInfo) {
    const tracePath = getTracePath(testInfo);
    await context.tracing.stop({ path: tracePath });
    return tracePath;
}
