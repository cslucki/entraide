import dotenv from 'dotenv';
import path from 'path';

// Load .env from project root
dotenv.config({ path: path.resolve(process.cwd(), '.env') });

// Required environment variables for testing
// Admin user - used for admin routes and dashboard testing
const requiredAdmin = ['TEST_ADMIN_LOGIN', 'TEST_ADMIN_PASSWORD'];

// Global platform members - used for all global platform tests
// These users are OUTSIDE any tenant/community
const requiredGlobalMembers = [
    'TEST_MEMBER1_LOGIN',
    'TEST_MEMBER1_PASSWORD',
    'TEST_MEMBER2_LOGIN',
    'TEST_MEMBER2_PASSWORD',
];

// CPME community members - for tenant-isolation testing
const requiredCpmeMembers = [
    'TEST_MEMBER_OF_CPME1_LOGIN',
    'TEST_MEMBER_OF_CPME1_PASSWORD',
    'TEST_MEMBER_OF_CPME2_LOGIN',
    'TEST_MEMBER_OF_CPME2_PASSWORD',
];

// Check required admin vars
const missingAdmin = requiredAdmin.filter(name => !process.env[name]);
if (missingAdmin.length > 0) {
    throw new Error(`Missing required admin environment variables: ${missingAdmin.join(', ')}`);
}

// Check required global member vars
const missingGlobal = requiredGlobalMembers.filter(name => !process.env[name]);
if (missingGlobal.length > 0) {
    throw new Error(`Missing required global member environment variables: ${missingGlobal.join(', ')}`);
}

// Check required CPME member vars
const missingCpme = requiredCpmeMembers.filter(name => !process.env[name]);
if (missingCpme.length > 0) {
    throw new Error(`Missing required CPME member environment variables: ${missingCpme.join(', ')}`);
}

// Log available users
console.log('Playwright test environment loaded:');
console.log(`  TEST_ADMIN: ${process.env.TEST_ADMIN_LOGIN ? '✓' : '✗'}`);
console.log(`  TEST_MEMBER1: ${process.env.TEST_MEMBER1_LOGIN ? '✓' : '✗'}`);
console.log(`  TEST_MEMBER2: ${process.env.TEST_MEMBER2_LOGIN ? '✓' : '✗'}`);
console.log(`  TEST_MEMBER_OF_CPME1: ${process.env.TEST_MEMBER_OF_CPME1_LOGIN ? '✓' : '✗'}`);
console.log(`  TEST_MEMBER_OF_CPME2: ${process.env.TEST_MEMBER_OF_CPME2_LOGIN ? '✓' : '✗'}`);
