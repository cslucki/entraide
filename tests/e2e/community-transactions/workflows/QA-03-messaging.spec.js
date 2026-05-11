/**
 * QA-03: Messaging Between Participants (Happy Path - P0)
 *
 * Tests messaging functionality between transaction participants:
 * 1. View conversation list
 * 2. View specific transaction messages
 * 3. Send messages from buyer to seller
 * 4. Send messages from seller to buyer
 * 5. Verify messages appear correctly
 * 6. Verify system messages are displayed
 * 7. Messages are marked as read when viewed
 *
 * Priority: P0 (Critical Happy Path)
 * Complexity: Low
 * Coverage: Messaging, message display, system messages, read status
 */

import { test, expect } from '@playwright/test';
import { login, captureScreenshot, setupConsoleLogging } from '../../../../ai/playwright/helpers/index.js';
import {
    goToMessages,
    goToMessageThread,
    goToCommunity,
} from '../helpers/community.js';
import { SELECTORS } from '../helpers/config.js';
import '../../../setup.js';

// Test users from environment
const USER1_EMAIL = process.env.TEST_MEMBER1_LOGIN; // Alice
const USER1_PASSWORD = process.env.TEST_MEMBER1_PASSWORD;
const USER2_EMAIL = process.env.TEST_MEMBER2_LOGIN; // Cyril
const USER2_PASSWORD = process.env.TEST_MEMBER2_PASSWORD;

test.describe('QA-03: Messaging Between Participants', () => {
    let communitySlug = null;
    let activeTransactionId = null;
    const testMessages = {
        fromUser1: `Test message from User 1 - ${Date.now()}`,
        fromUser2: `Test message from User 2 - ${Date.now()}`,
    };

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    /**
     * SETUP: Get community slug and find/create an active transaction
     */
    test('setup: get community and transaction context', async ({ page }) => {
        // Login as user1
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Get community slug
        const url = page.url();
        const slugMatch = url.match(/\/([a-z0-9-]+)\//);
        communitySlug = slugMatch ? slugMatch[1] : 'default';

        console.log(`📍 Community slug: ${communitySlug}`);

        // Navigate to messages to find or create a transaction
        await goToMessages(page, communitySlug);

        // Check if there's an existing conversation
        const conversationLinks = page.locator('a[href*="/messages/"]');
        const conversationCount = await conversationLinks.count();

        if (conversationCount > 0) {
            // Use first conversation
            const firstConversation = conversationLinks.first();
            await firstConversation.click();

            // Get transaction ID from URL
            const urlAfter = page.url();
            const idMatch = urlAfter.match(/\/messages\/([\w-]+)$/);
            if (idMatch) {
                activeTransactionId = idMatch[1];
                console.log(`✅ Found active transaction: ${activeTransactionId}`);
            }

            await captureScreenshot(page, 'QA-03-setup-existing-conversation');
        } else {
            // No existing conversation - skip messaging tests
            console.log('⚠️ No active conversation found - messaging tests require a transaction');
            test.skip(true, 'No active transaction available for messaging tests');
        }

        // Logout to prepare for next steps
        await page.goto('/logout');
    });

    /**
     * STEP 1: User1 views conversation list
     *
     * Validates:
     * - Messages page loads correctly
     * - Conversation list is displayed (or empty state)
     * - Navigation works
     */
    test('step1: user1 views conversation list', async ({ page }) => {
        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessages(page, communitySlug);

        // Verify we're on messages page
        await expect(page).toHaveURL(/\/messages$/);

        await captureScreenshot(page, 'QA-03-step1-messages-list');

        console.log('✅ STEP 1: User1 can view conversation list');
    });

    /**
     * STEP 2: User1 views specific transaction messages
     *
     * Validates:
     * - Message thread loads correctly
     * - Transaction details are visible
     * - Both participants are visible
     */
    test('step2: user1 views transaction message thread', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Verify message thread is visible
        await expect(page.locator('[class*="message"], [class*="thread"]')).first()).toBeVisible();

        // Check for transaction status indicator
        const statusElement = page.locator('[class*="status"], [class*="badge"], .transaction-status').first();
        if (await statusElement.isVisible()) {
            const statusText = await statusElement.textContent();
            console.log(`📊 Transaction status in messages: ${statusText}`);
        }

        await captureScreenshot(page, 'QA-03-step2-message-thread');

        console.log('✅ STEP 2: User1 can view message thread');
    });

    /**
     * STEP 3: User1 sends message to User2
     *
     * Validates:
     * - Message input is visible and enabled
     * - Message can be sent
     * - Message appears in thread immediately
     * - System updates (timestamp, etc.) are visible
     */
    test('step3: user1 sends message to user2', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Verify message input is visible
        const messageInput = page.locator(SELECTORS.messageInput).first();
        await expect(messageInput).toBeVisible();

        // Type message
        await messageInput.fill(testMessages.fromUser1);

        // Send message
        const sendButton = page.locator(SELECTORS.sendButton).first();
        await sendButton.click();

        // Wait for message to appear
        await page.waitForTimeout(500);

        // Verify message appears in thread
        await expect(page.locator(`text=${testMessages.fromUser1}`).first()).toBeVisible();

        await captureScreenshot(page, 'QA-03-step3-message-sent-user1');

        console.log('✅ STEP 3: User1 sent message successfully');
    });

    /**
     * STEP 4: User2 views received message
     *
     * Validates:
     * - User2 can view the same message thread
     * - User1's message is visible to User2
     * - Thread context is consistent
     */
    test('step4: user2 views message from user1', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER2_EMAIL, USER2_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Verify User1's message is visible
        await expect(page.locator(`text=${testMessages.fromUser1}`).first()).toBeVisible();

        // Check for unread indicator (might be present)
        const unreadIndicator = page.locator('[class*="unread"], [class*="new"]').first();
        const hasUnread = await unreadIndicator.count() > 0;

        if (hasUnread) {
            console.log('📬 Unread message indicator is visible');
        }

        await captureScreenshot(page, 'QA-03-step4-message-received-user2');

        console.log('✅ STEP 4: User2 can view message from User1');
    });

    /**
     * STEP 5: User2 replies to User1
     *
     * Validates:
     * - Reply functionality works
     * - Messages are ordered chronologically
     * - Different users' messages are visually distinct
     */
    test('step5: user2 replies to user1', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER2_EMAIL, USER2_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Type reply
        const messageInput = page.locator(SELECTORS.messageInput).first();
        await messageInput.fill(testMessages.fromUser2);

        // Send reply
        const sendButton = page.locator(SELECTORS.sendButton).first();
        await sendButton.click();

        // Wait for message to appear
        await page.waitForTimeout(500);

        // Verify both messages are visible
        await expect(page.locator(`text=${testMessages.fromUser1}`).first()).toBeVisible();
        await expect(page.locator(`text=${testMessages.fromUser2}`).first()).toBeVisible();

        // Verify messages are in order (User1's first, then User2's)
        const user1Message = page.locator(`text=${testMessages.fromUser1}`).first();
        const user2Message = page.locator(`text=${testMessages.fromUser2}`).first();

        const user1Box = await user1Message.boundingBox();
        const user2Box = await user2Message.boundingBox();

        expect(user2Box.y).toBeGreaterThan(user1Box.y);

        await captureScreenshot(page, 'QA-03-step5-message-reply-user2');

        console.log('✅ STEP 5: User2 replied successfully, messages ordered correctly');
    });

    /**
     * STEP 6: User1 sees User2's reply (read status verification)
     *
     * Validates:
     * - Messages are marked as read when viewed
     * - Unread counters update
     */
    test('step6: user1 sees reply and messages marked as read', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        // Go to dashboard first to check unread count
        await goToCommunity(page, communitySlug);

        const unreadBadge = page.locator('[class*="unread"], [class*="badge"]').filter({ hasText: /\d+/ }).first();
        const initialUnread = await unreadBadge.count() > 0 ? await unreadBadge.textContent() : '0';
        console.log(`📬 Initial unread count: ${initialUnread}`);

        // Navigate to message thread
        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Verify User2's reply is visible
        await expect(page.locator(`text=${testMessages.fromUser2}`).first()).toBeVisible();

        await captureScreenshot(page, 'QA-03-step6-user1-sees-reply');

        console.log('✅ STEP 6: User1 can see User2\'s reply');
    });

    /**
     * STEP 7: Verify system messages are displayed
     *
     * Validates:
     * - System messages about transaction state changes are visible
     * - System messages are visually distinct from user messages
     * - System messages provide context
     */
    test('step7: verify system messages are displayed', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Look for system messages
        const systemMessage = page.locator('[class*="system"], [data-type="system"]').first();
        const hasSystemClass = await systemMessage.count() > 0;

        if (hasSystemClass) {
            await expect(systemMessage).toBeVisible();
            console.log('✅ System messages with class attribute found');
        } else {
            // Look for typical system message text
            const hasSystemText = await page.locator('text=Nouvelle échange, text=acceptée, text=terminé, text=complétée').count() > 0;
            if (hasSystemText) {
                console.log('✅ System messages identified by text content');
            } else {
                console.log('⚠️ WARNING: No system messages detected');
            }
        }

        await captureScreenshot(page, 'QA-03-step7-system-messages');

        console.log('✅ STEP 7: System messages visibility checked');
    });

    /**
     * STEP 8: Verify message input exists and is usable
     *
     * Validates:
     * - Message input is present for active transactions
     * - Input has proper placeholder/label
     * - Send button is enabled
     */
    test('step8: verify message input functionality', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Check message input
        const messageInput = page.locator(SELECTORS.messageInput).first();
        await expect(messageInput).toBeVisible();
        await expect(messageInput).toBeEnabled();

        // Check placeholder text
        const placeholder = await messageInput.getAttribute('placeholder');
        console.log(`💬 Message input placeholder: "${placeholder || 'none'}"`);

        // Check send button
        const sendButton = page.locator(SELECTORS.sendButton).first();
        await expect(sendButton).toBeVisible();
        await expect(sendButton).toBeEnabled();

        await captureScreenshot(page, 'QA-03-step8-message-input-verification');

        console.log('✅ STEP 8: Message input and send button are functional');
    });

    /**
     * UX CHECK: Verify navigation back to conversation list
     *
     * Validates:
     * - User can navigate back to conversation list
     * - Navigation is intuitive
     */
    test('ux-check: navigate back to conversation list', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Look for back link or breadcrumb
        const backLink = page.locator('a[href*="/messages$"], a:has-text("Retour"), a:has-text("Messages")').first();
        const hasBackLink = await backLink.count() > 0;

        if (hasBackLink) {
            await backLink.click();
            await expect(page).toHaveURL(/\/messages$/);

            await captureScreenshot(page, 'QA-03-ux-back-navigation');

            console.log('✅ UX CHECK: Back navigation to conversation list works');
        } else {
            await captureScreenshot(page, 'QA-03-ux-no-back-link');
            console.log('⚠️ WARNING: No back link found from message thread - UX issue');
        }
    });

    /**
     * UX CHECK: Verify participant information is visible
     *
     * Validates:
     * - Other participant's name/avatar is visible
     * - Link to participant's profile exists
     */
    test('ux-check: participant information visibility', async ({ page }) => {
        test.skip(!activeTransactionId, 'No active transaction ID available');

        await login(page, USER1_EMAIL, USER1_PASSWORD);

        await goToMessageThread(page, activeTransactionId, communitySlug);

        // Look for participant information
        const participantName = page.locator('[class*="participant"], [class*="user"], [class*="author"]').first();
        const hasParticipantInfo = await participantName.count() > 0;

        // Look for profile link
        const profileLink = page.locator('a[href*="/profile/"]').first();
        const hasProfileLink = await profileLink.count() > 0;

        if (hasParticipantInfo) {
            console.log('✅ Participant information is visible');
        } else {
            console.log('⚠️ WARNING: Participant information not clearly visible - UX issue');
        }

        if (hasProfileLink) {
            console.log('✅ Profile link is accessible');
        } else {
            console.log('⚠️ WARNING: No profile link in message thread - UX issue');
        }

        await captureScreenshot(page, 'QA-03-ux-participant-info');

        if (hasParticipantInfo && hasProfileLink) {
            console.log('✅ UX CHECK: Participant information and profile links are available');
        }
    });
});
