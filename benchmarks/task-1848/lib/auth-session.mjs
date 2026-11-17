// TASK-1848 — session HTTP REELLE (login + jeton CSRF), comptes de TEST
// uniquement (voir database/seeders/Task1848BenchmarkSeeder.php).
//
// `fetch` ne gere AUCUN cookie entre deux appels : ce module tient un
// mini pot a cookies par client simule, et suit chaque redirection a la
// main (`redirect: 'manual'`) pour ne JAMAIS perdre un `Set-Cookie` pose
// sur une etape intermediaire (le 302 du login POST lui-meme pose la
// session fraiche ET le jeton XSRF — les perdre en suivant la chaine
// automatiquement aurait fait echouer l'etape suivante).

export class CookieJar {
    constructor() {
        this.cookies = new Map();
    }

    absorb(res) {
        const setCookies = typeof res.headers.getSetCookie === 'function'
            ? res.headers.getSetCookie()
            : (res.headers.get('set-cookie') ? [res.headers.get('set-cookie')] : []);

        for (const raw of setCookies) {
            const [pair] = raw.split(';');
            const eq = pair.indexOf('=');
            if (eq === -1) continue;
            const name = pair.slice(0, eq).trim();
            const value = pair.slice(eq + 1).trim();
            this.cookies.set(name, value);
        }
    }

    header() {
        return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
    }

    get(name) {
        return this.cookies.get(name);
    }
}

function extractToken(html, attrName) {
    const re = new RegExp(`name="${attrName}"\\s+(?:value|content)="([^"]+)"`);
    const m = html.match(re);
    return m ? m[1] : null;
}

/**
 * Suit une chaine de redirections A LA MAIN (jamais `redirect: 'follow'`)
 * pour que CHAQUE Set-Cookie intermediaire (le 302 du login en particulier)
 * rejoigne le pot avant l'etape suivante.
 */
async function followManually(baseUrl, url, jar, maxHops = 5) {
    let current = url;
    for (let hop = 0; hop < maxHops; hop++) {
        const res = await fetch(current, {
            redirect: 'manual',
            headers: { Cookie: jar.header() },
        });
        jar.absorb(res);

        if (res.status >= 300 && res.status < 400) {
            const location = res.headers.get('location');
            current = new URL(location, current).toString();
            continue;
        }

        return res;
    }
    throw new Error(`TASK-1848: trop de redirections depuis ${url}`);
}

/**
 * Login REEL (flux web standard : page -> jeton CSRF -> POST), sur la
 * route PREFIXEE par l'Organization — la route courte resoudrait
 * l'Organization PAR DEFAUT DE LA PLATEFORME (TASK-1847) et ne verrait
 * jamais un compte de benchmark qui vit dans une AUTRE Organization.
 *
 * Retourne { jar, csrfToken } : `csrfToken` est RE-LU sur une page APRES
 * connexion (`session()->regenerate()` dans `AuthenticatedSessionController`
 * invalide celui lu sur la page de login).
 */
export async function login(baseUrl, orgSlug, email, password) {
    const jar = new CookieJar();

    const loginPageRes = await fetch(`${baseUrl}/org/${orgSlug}/login`, {
        headers: { Cookie: jar.header() },
    });
    jar.absorb(loginPageRes);
    const loginHtml = await loginPageRes.text();
    const token = extractToken(loginHtml, '_token');
    if (!token) {
        throw new Error(`TASK-1848: jeton CSRF introuvable sur la page de login (${email})`);
    }

    const body = new URLSearchParams({ _token: token, email, password });
    const loginPostRes = await fetch(`${baseUrl}/org/${orgSlug}/login`, {
        method: 'POST',
        redirect: 'manual',
        headers: {
            Cookie: jar.header(),
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body,
    });
    jar.absorb(loginPostRes);

    const location = loginPostRes.headers.get('location') ?? '';
    if (location.includes('/login')) {
        throw new Error(`TASK-1848: echec de connexion pour ${email} (redirection vers /login)`);
    }

    const landingUrl = new URL(location, baseUrl).toString();
    const landingRes = await followManually(baseUrl, landingUrl, jar);
    const landingHtml = await landingRes.text();
    const csrfToken = extractToken(landingHtml, 'csrf-token');
    if (!csrfToken) {
        throw new Error(`TASK-1848: jeton CSRF introuvable apres connexion (${email})`);
    }

    return { jar, csrfToken, baseUrl };
}

/**
 * Authorizer `laravel-echo` REEL : reproduit exactement l'appel que le
 * navigateur ferait vers `/broadcasting/auth`, avec la session et le
 * jeton CSRF obtenus par `login()` — jamais un raccourci qui fabriquerait
 * la signature a la main.
 */
export function makeAuthorizer(session) {
    return (channel, options) => ({
        authorize: async (socketId, callback) => {
            try {
                const res = await fetch(`${session.baseUrl}/broadcasting/auth`, {
                    method: 'POST',
                    headers: {
                        Cookie: session.jar.header(),
                        'Content-Type': 'application/x-www-form-urlencoded',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': session.csrfToken,
                    },
                    body: new URLSearchParams({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                });
                session.jar.absorb(res);

                if (!res.ok) {
                    callback(true, { status: res.status, text: await res.text() });
                    return;
                }

                const data = await res.json();
                callback(false, data);
            } catch (err) {
                callback(true, err);
            }
        },
    });
}
