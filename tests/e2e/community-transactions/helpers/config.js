/**
 * Configuration constants for community transaction tests
 */

// Get API base URL from environment or default to current origin
export const API_BASE_URL = process.env.PLAYWRIGHT_API_BASE_URL || 'https://test.laravel';

// Community routes
export const COMMUNITY_ROUTES = {
    dashboard: (slug) => `/${slug}/dashboard`,
    services: (slug) => `/${slug}/services`,
    serviceCreate: (slug) => `/${slug}/services/create`,
    serviceShow: (slug, id) => `/${slug}/services/${id}`,
    serviceEdit: (slug, id) => `/${slug}/services/${id}/edit`,
    requests: (slug) => `/${slug}/requests`,
    requestCreate: (slug) => `/${slug}/requests/create`,
    requestShow: (slug, id) => `/${slug}/requests/${id}`,
    messages: (slug) => `/${slug}/messages`,
    messageThread: (slug, id) => `/${slug}/messages/${id}`,
    members: (slug) => `/${slug}/membres`,
    exchanges: (slug) => `/${slug}/echanges`,
    blog: (slug) => `/${slug}/blog`,
    favorites: (slug) => `/${slug}/favorites`,
};

// Transaction statuses
export const TRANSACTION_STATUS = {
    PENDING: 'pending',
    ACCEPTED: 'accepted',
    BUYER_DONE: 'buyer_done',
    COMPLETED: 'completed',
    REFUSED: 'refused',
    CANCELLED: 'cancelled',
};

// Transaction status labels (French)
export const TRANSACTION_LABELS = {
    [TRANSACTION_STATUS.PENDING]: 'En attente',
    [TRANSACTION_STATUS.ACCEPTED]: 'Acceptée',
    [TRANSACTION_STATUS.BUYER_DONE]: 'En attente de confirmation',
    [TRANSACTION_STATUS.COMPLETED]: 'Terminée',
    [TRANSACTION_STATUS.REFUSED]: 'Refusée',
    [TRANSACTION_STATUS.CANCELLED]: 'Annulée',
};

// Service statuses
export const SERVICE_STATUS = {
    ACTIVE: 'active',
    PAUSED: 'paused',
    DELETED: 'deleted',
};

// Request statuses
export const REQUEST_STATUS = {
    OPEN: 'open',
    IN_PROGRESS: 'in_progress',
    CLOSED: 'closed',
};

// Common selectors
export const SELECTORS = {
    // Transaction actions
    proposeButton: 'button:has-text("Proposer"), button:has-text("Échanger"), a:has-text("Proposer")',
    acceptButton: 'button:has-text("Accepter")',
    refuseButton: 'button:has-text("Refuser")',
    cancelButton: 'button:has-text("Annuler")',
    completeButton: 'button:has-text("Terminer"), button:has-text("Confirmer la fin")',
    confirmButton: 'button:has-text("Confirmer"), button:has-text("Valider")',
    contestButton: 'button:has-text("Contester")',

    // Message
    messageInput: 'textarea[name="message"], textarea[placeholder*="message"], input[name="message"]',
    sendButton: 'button:has-text("Envoyer"), button:has-text("Send")',

    // Review
    ratingInput: 'input[name="rating"]',
    reviewComment: 'textarea[name="comment"]',
    submitReview: 'button:has-text("Envoyer"), button:has-text("Soumettre")',

    // Navigation
    dashboardLink: 'a[href*="/dashboard"]',
    messagesLink: 'a[href*="/messages"]',
    servicesLink: 'a[href*="/services"]',

    // Status indicators
    statusBadge: '[class*="status"], [class*="badge"]',
    unreadCount: '[class*="unread"], [class*="badge"][data-count]',
};

// Default test values
export const TEST_VALUES = {
    serviceTitle: 'Service de test Playwright',
    serviceDescription: 'Service créé automatiquement pour les tests Playwright.',
    servicePoints: 10,
    requestTitle: 'Création d\'un logo pour mon association',
    requestDescription: 'Besoin d\'aide pour créer un logo professionnel pour mon association sportive. J\'ai besoin de couleurs, d\'un design moderne et d\'un format adaptable.',
    requestPoints: 10,
    message: 'Message de test Playwright',
    reviewComment: 'Test review from Playwright',
    reviewRating: 5,
};
