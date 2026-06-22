const TS = () => Date.now().toString(36);

export function uniqueName(prefix) {
  return `QA-T146-${prefix}-${TS()}`;
}

export function uniqueTitle(prefix) {
  return `${prefix} ${TS()} — test automa`;
}

export const LONG_DESC = 'Ceci est un test automatisé créé par la suite Playwright T146 de BouclePro. Il permet de valider le bon fonctionnement du formulaire de création et de gestion. Merci de ne pas supprimer ou modifier.';

export const LONG_CONTENT = 'Ceci est un article de blog créé automatiquement par la suite de tests Playwright T146. Il permet de valider le bon fonctionnement du module blog de BouclePro. Le contenu est suffisamment long pour respecter les contraintes de validation.';

export const QA = {
  ADMIN: { email: process.env.TEST_ADMIN_LOGIN, password: process.env.TEST_ADMIN_PASSWORD },
  M1: { email: process.env.TEST_MEMBER1_LOGIN, password: process.env.TEST_MEMBER1_PASSWORD },
  M2: { email: process.env.TEST_MEMBER2_LOGIN, password: process.env.TEST_MEMBER2_PASSWORD },
};

export const CATEGORY_INDEX = 1;
export const POINTS = 50;
export const BUDGET_MIN = 10;
