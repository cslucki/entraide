/**
 * TASK-1886 — les messages vocaux de la messagerie privee.
 *
 * Deux composants Alpine, enregistres par `app.js` :
 *
 * - `voiceRecorder`   : le micro du composeur. Enregistre avec MediaRecorder,
 *   dessine la voix en direct, puis envoie par `$wire.upload('voice', …)` en
 *   montrant la progression REELLE de l'envoi (la « mire »).
 * - `voiceNotePlayer` : le lecteur d'une bulle, facon WhatsApp — bouton
 *   lecture, forme d'onde qui se remplit, deplacement au doigt, vitesse.
 *
 * ## La forme d'onde voyage avec le vocal
 *
 * Elle est mesuree PENDANT l'enregistrement (volume RMS toutes les ~60 ms),
 * reduite a `NB_BARRES` valeurs de 0 a 100, et envoyee avec le fichier. Le
 * lecteur n'a donc jamais besoin de telecharger ni de decoder l'audio pour la
 * dessiner : sur un telephone en donnees mobiles, un fil de vingt vocaux ne
 * coute rien tant qu'on n'appuie pas sur lecture. C'est aussi ce que fait
 * WhatsApp.
 *
 * ## Pourquoi la duree vient du serveur
 *
 * Un WebM produit par MediaRecorder (Chrome, Android) n'ecrit pas sa duree
 * dans son en-tete : `audio.duration` vaut `Infinity` jusqu'a la fin de la
 * lecture. La duree mesuree a l'enregistrement est donc la reference
 * d'affichage ; celle du navigateur ne la remplace que si elle est finie.
 */

export const NB_BARRES = 40;

/** Le format que CE navigateur sait enregistrer, dans l'ordre de preference. */
function choisirFormat() {
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
        return '';
    }

    const candidats = [
        'audio/webm;codecs=opus', // Chrome, Edge, Android
        'audio/mp4',              // Safari iOS / macOS
        'audio/ogg;codecs=opus',  // Firefox
        'audio/webm',
    ];

    return candidats.find((type) => MediaRecorder.isTypeSupported(type)) ?? '';
}

function extensionPour(type) {
    if (type.startsWith('audio/mp4')) return 'm4a';
    if (type.startsWith('audio/ogg')) return 'ogg';
    return 'webm';
}

/**
 * Reduit une serie de niveaux a `nb` barres, en gardant le PIC de chaque
 * tranche : une moyenne effacerait les syllabes et donnerait une onde plate.
 * Normalise ensuite sur le plus fort, pour qu'une voix douce ne dessine pas
 * une ligne au ras du sol.
 *
 * @param {number[]} niveaux
 * @param {number} nb
 * @returns {number[]} entiers 0..100
 */
export function reduireForme(niveaux, nb = NB_BARRES) {
    if (! niveaux.length) {
        return Array(nb).fill(8);
    }

    const tranche = niveaux.length / nb;
    const pics = [];

    for (let i = 0; i < nb; i++) {
        const debut = Math.floor(i * tranche);
        const fin = Math.max(debut + 1, Math.floor((i + 1) * tranche));
        let pic = 0;
        for (let j = debut; j < fin && j < niveaux.length; j++) {
            pic = Math.max(pic, niveaux[j]);
        }
        pics.push(pic);
    }

    const max = Math.max(...pics, 0.0001);

    // Plancher de 8 : un silence reste une barre visible, pas un trou.
    return pics.map((p) => Math.max(8, Math.round((p / max) * 100)));
}

/** « 0:07 », « 4:59 ». */
export function formaterDuree(secondes) {
    const s = Math.max(0, Math.floor(Number.isFinite(secondes) ? secondes : 0));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/* ===================================================================== *
 *  L'ENREGISTREUR                                                        *
 * ===================================================================== */

/**
 * Le geste est celui de WhatsApp Web (captures Cyril, 23/11) :
 *
 *   repos       : le micro, a droite du champ.
 *   enregistre  : corbeille · point rouge · duree · onde en direct · PAUSE · envoyer
 *   pause       : corbeille · LECTURE · onde + curseur · duree · MICRO (reprendre) · envoyer
 *   envoi       : l'onde se fige, l'anneau du bouton envoyer se remplit (la mire)
 *
 * La pause n'arrete pas l'enregistrement, elle le SUSPEND (`MediaRecorder.pause`) :
 * le micro rouge reprend a la suite, dans le meme fichier, comme WhatsApp.
 *
 * @param {{ maxSeconds: number, i18n: Record<string, string> }} config
 */
export function voiceRecorder(config = {}) {
    const maxSeconds = config.maxSeconds ?? 300;

    return {
        /** 'repos' | 'demande' | 'enregistre' | 'pause' | 'envoi' | 'envoye' | 'erreur' */
        etat: 'repos',
        secondes: 0,
        /** Les barres du direct : les plus recentes a droite, comme WhatsApp. */
        direct: Array(30).fill(10),
        progression: 0,
        progressionMesurable: false,
        erreur: '',

        /* L'ecoute avant envoi (etat « pause »). */
        apercuBarres: [],
        apercuLecture: false,
        apercuPosition: 0,

        _flux: null,
        _enregistreur: null,
        _morceaux: [],
        _niveaux: [],
        _contexte: null,
        _analyseur: null,
        _boucle: null,
        _minuteur: null,
        _cumul: 0,
        _reprise: 0,
        _annule: false,
        _format: '',
        _apercu: null,
        _apercuUrl: null,

        get vocalActif() {
            return this.etat !== 'repos';
        },

        get dureeLisible() {
            return formaterDuree(this.secondes);
        },

        /** En pause : le temps ecoule pendant l'ecoute, sinon la duree enregistree. */
        get apercuCompteur() {
            return formaterDuree(this.apercuLecture || this.apercuPosition > 0 ? this.apercuPosition : this.secondes);
        },

        get apercuRatio() {
            return this.secondes > 0 ? Math.min(1, this.apercuPosition / this.secondes) : 0;
        },

        apercuBarreJouee(index) {
            return (index + 0.5) / Math.max(1, this.apercuBarres.length) <= this.apercuRatio;
        },

        /** Millisecondes reellement enregistrees, pauses exclues. */
        dureeEnregistree() {
            return this._cumul + (this.etat === 'enregistre' ? performance.now() - this._reprise : 0);
        },

        async demarrer() {
            if (this.etat !== 'repos' && this.etat !== 'erreur') {
                return;
            }

            this.erreur = '';

            if (! navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
                this.echouer(config.i18n?.unsupported ?? '');
                return;
            }

            this.etat = 'demande';

            try {
                this._flux = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                });
            } catch (e) {
                // NotAllowedError : refus du membre ou du navigateur. Le message
                // dit OU rendre l'acces, pas seulement qu'il manque.
                this.echouer(e?.name === 'NotAllowedError'
                    ? (config.i18n?.denied ?? '')
                    : (config.i18n?.unavailable ?? ''));
                return;
            }

            // Annule PENDANT la demande d'acces : le membre a renonce avant
            // meme que le micro s'ouvre. On le referme aussitot.
            if (this.etat !== 'demande') {
                this.liberer();
                return;
            }

            this._format = choisirFormat();
            this._morceaux = [];
            this._niveaux = [];
            this._annule = false;
            this._cumul = 0;
            this.direct = Array(30).fill(10);
            this.secondes = 0;

            try {
                this._enregistreur = this._format
                    ? new MediaRecorder(this._flux, { mimeType: this._format, audioBitsPerSecond: 48000 })
                    : new MediaRecorder(this._flux);
            } catch {
                this.liberer();
                this.echouer(config.i18n?.unsupported ?? '');
                return;
            }

            this._enregistreur.addEventListener('dataavailable', (e) => {
                if (e.data && e.data.size > 0) this._morceaux.push(e.data);
            });

            this.brancherLeNiveau();

            this._enregistreur.start(250);
            this.etat = 'enregistre';
            this.lancerLeChrono();

            if (navigator.vibrate) navigator.vibrate(18);
        },

        lancerLeChrono() {
            this._reprise = performance.now();
            clearInterval(this._minuteur);

            this._minuteur = setInterval(() => {
                this.secondes = Math.floor(this.dureeEnregistree() / 1000);

                // Le plafond : on n'interrompt pas en silence, on ENVOIE ce
                // qui a ete dit. Perdre cinq minutes de parole a la limite
                // serait la pire issue possible.
                if (this.secondes >= maxSeconds) {
                    this.envoyer();
                }
            }, 200);

            this.ecouterLeNiveau();
        },

        /** Le niveau sonore : il anime l'onde en direct ET devient la forme envoyee. */
        brancherLeNiveau() {
            try {
                const Contexte = window.AudioContext || window.webkitAudioContext;
                this._contexte = new Contexte();
                const source = this._contexte.createMediaStreamSource(this._flux);
                this._analyseur = this._contexte.createAnalyser();
                this._analyseur.fftSize = 1024;
                source.connect(this._analyseur);
            } catch {
                this._analyseur = null;
            }
        },

        ecouterLeNiveau() {
            cancelAnimationFrame(this._boucle);

            if (! this._analyseur) return;

            const tampon = new Float32Array(this._analyseur.fftSize);
            let dernier = 0;

            const tick = (t) => {
                if (! this._analyseur || this.etat !== 'enregistre') return;

                if (t - dernier >= 60) {
                    dernier = t;
                    this._analyseur.getFloatTimeDomainData(tampon);
                    let somme = 0;
                    for (let i = 0; i < tampon.length; i++) somme += tampon[i] * tampon[i];
                    const rms = Math.sqrt(somme / tampon.length);

                    this._niveaux.push(rms);

                    // Echelle perceptive : la voix parlee tient entre 0,01 et
                    // 0,3 de RMS ; une racine l'etale sur toute la hauteur.
                    const hauteur = Math.max(10, Math.min(100, Math.round(Math.sqrt(rms) * 180)));
                    this.direct = [...this.direct.slice(1), hauteur];
                }

                this._boucle = requestAnimationFrame(tick);
            };

            this._boucle = requestAnimationFrame(tick);
        },

        /** PAUSE : on suspend, et on peut reecouter ce qui a ete dit. */
        suspendre() {
            if (this.etat !== 'enregistre' || ! this._enregistreur) return;

            this._cumul += performance.now() - this._reprise;
            this.secondes = Math.max(1, Math.round(this._cumul / 1000));
            clearInterval(this._minuteur);
            cancelAnimationFrame(this._boucle);

            this.etat = 'pause';
            this.apercuBarres = reduireForme(this._niveaux, NB_BARRES);
            this.apercuPosition = 0;
            this.apercuLecture = false;

            // `requestData()` force la remise du morceau en cours : sans lui,
            // l'ecoute s'arreterait jusqu'a 250 ms avant la fin reelle.
            this._enregistreur.addEventListener('dataavailable', () => this.preparerLApercu(), { once: true });
            this._enregistreur.pause();
            try { this._enregistreur.requestData(); } catch { this.preparerLApercu(); }
        },

        preparerLApercu() {
            this.oublierLApercu();
            const type = this._enregistreur?.mimeType || this._format || 'audio/webm';
            this._apercuUrl = URL.createObjectURL(new Blob(this._morceaux, { type }));

            const audio = new Audio(this._apercuUrl);
            audio.addEventListener('timeupdate', () => { this.apercuPosition = audio.currentTime; });
            audio.addEventListener('play', () => { this.apercuLecture = true; });
            audio.addEventListener('pause', () => { this.apercuLecture = false; });
            audio.addEventListener('ended', () => { this.apercuLecture = false; this.apercuPosition = 0; });
            this._apercu = audio;
        },

        basculerLApercu() {
            if (! this._apercu) return;
            this._apercu.paused ? this._apercu.play().catch(() => {}) : this._apercu.pause();
        },

        chercherDansLApercu(evenement) {
            if (! this._apercu || ! this.secondes) return;
            const zone = evenement.currentTarget.getBoundingClientRect();
            const ratio = Math.max(0, Math.min(1, (evenement.clientX - zone.left) / zone.width));
            this.apercuPosition = ratio * this.secondes;
            try { this._apercu.currentTime = this.apercuPosition; } catch { /* WebM sans index : ignore */ }
        },

        oublierLApercu() {
            this._apercu?.pause();
            this._apercu = null;
            if (this._apercuUrl) URL.revokeObjectURL(this._apercuUrl);
            this._apercuUrl = null;
            this.apercuLecture = false;
            this.apercuPosition = 0;
        },

        /** Le micro rouge : reprend A LA SUITE, dans le meme enregistrement. */
        reprendre() {
            if (this.etat !== 'pause' || ! this._enregistreur) return;

            this.oublierLApercu();
            this._enregistreur.resume();
            this.etat = 'enregistre';
            this.lancerLeChrono();
        },

        annuler() {
            this._annule = true;
            this.oublierLApercu();
            this.arreterLaCapture();
            this.liberer();
            this.etat = 'repos';
            this.secondes = 0;
        },

        /** Arrete le micro et rend le fichier enregistre. */
        arreterLaCapture() {
            clearInterval(this._minuteur);
            cancelAnimationFrame(this._boucle);
            this._analyseur = null;

            const enregistreur = this._enregistreur;

            if (! enregistreur || enregistreur.state === 'inactive') {
                return Promise.resolve(null);
            }

            return new Promise((resoudre) => {
                enregistreur.addEventListener('stop', () => {
                    const type = enregistreur.mimeType || this._format || 'audio/webm';
                    resoudre(new Blob(this._morceaux, { type }));
                }, { once: true });
                enregistreur.stop();
            });
        },

        liberer() {
            this._flux?.getTracks().forEach((piste) => piste.stop());
            this._flux = null;
            this._contexte?.close?.().catch(() => {});
            this._contexte = null;
        },

        async envoyer() {
            if (this.etat !== 'enregistre' && this.etat !== 'pause') {
                return;
            }

            const duree = Math.max(1, Math.round(this.dureeEnregistree() / 1000));
            this.oublierLApercu();
            this.etat = 'envoi';
            this.secondes = duree;
            const blob = await this.arreterLaCapture();
            this.liberer();

            if (this._annule || ! blob || blob.size === 0) {
                this.etat = 'repos';
                return;
            }

            // Un appui accidentel sur le micro n'est pas un message : WhatsApp
            // l'ecarte de la meme facon.
            if (blob.size < 1200) {
                this.etat = 'repos';
                return;
            }

            const type = blob.type || 'audio/webm';
            const fichier = new File([blob], `vocal.${extensionPour(type)}`, { type });

            this.progression = 0;
            this.progressionMesurable = false;
            const debutEnvoi = Date.now();

            // Duree et forme partent AVANT le fichier, sans requete propre :
            // elles voyageront avec l'upload et `sendMessage()`.
            this.$wire.set('voiceDuration', Math.min(duree, maxSeconds), false);
            this.$wire.set('voiceWaveform', reduireForme(this._niveaux), false);

            this.$wire.upload(
                'voice',
                fichier,
                () => {
                    this.progression = 100;
                    this.$wire.sendMessage().then(() => {
                        // Le PLANCHER d'affichage (meme raison que
                        // `depot-mire`) : un vocal de 80 Ko part en un
                        // battement de cil, et une mire invisible ne rassure
                        // personne. La coche reste un instant.
                        const reste = Math.max(0, 700 - (Date.now() - debutEnvoi));
                        setTimeout(() => {
                            this.etat = 'envoye';
                            setTimeout(() => { this.etat = 'repos'; this.secondes = 0; }, 650);
                        }, reste);
                    }).catch(() => this.echouer(config.i18n?.failed ?? ''));
                },
                () => this.echouer(config.i18n?.failed ?? ''),
                (e) => {
                    const p = Math.round(e?.detail?.progress ?? 0);
                    if (p > 0 && p < 100) this.progressionMesurable = true;
                    this.progression = p;
                },
            );
        },

        echouer(message) {
            this.erreur = message;
            this.etat = 'erreur';
            setTimeout(() => {
                if (this.etat === 'erreur') {
                    this.etat = 'repos';
                    this.erreur = '';
                }
            }, 4000);
        },

        destroy() {
            this._annule = true;
            clearInterval(this._minuteur);
            cancelAnimationFrame(this._boucle);
            this.oublierLApercu();
            if (this._enregistreur && this._enregistreur.state !== 'inactive') {
                try { this._enregistreur.stop(); } catch { /* deja arrete */ }
            }
            this.liberer();
        },
    };
}

/* ===================================================================== *
 *  LE LECTEUR                                                            *
 * ===================================================================== */

const VITESSES = [1, 1.5, 2];

/**
 * @param {{ id: string, duration: number|null, peaks: number[]|null }} config
 */
export function voiceNotePlayer(config = {}) {
    return {
        id: config.id ?? '',
        lecture: false,
        chargement: false,
        position: 0,
        duree: Number(config.duration) || 0,
        vitesse: 1,
        dejaLance: false,
        /** Vrai pendant qu'un doigt (ou la souris) glisse sur l'onde. */
        glisse: false,
        barres: Array.isArray(config.peaks) && config.peaks.length
            ? config.peaks.slice(0, NB_BARRES).map((v) => Math.max(8, Math.min(100, Number(v) || 0)))
            : reduireForme([], NB_BARRES).map((_, i) => 18 + ((i * 37) % 50)),

        init() {
            const audio = this.$refs.audio;

            audio.addEventListener('timeupdate', () => {
                this.position = audio.currentTime;
            });
            audio.addEventListener('loadedmetadata', () => {
                if (Number.isFinite(audio.duration) && audio.duration > 0) {
                    this.duree = audio.duration;
                }
            });
            audio.addEventListener('playing', () => {
                this.chargement = false;
                this.lecture = true;
            });
            audio.addEventListener('waiting', () => { this.chargement = true; });
            audio.addEventListener('pause', () => { this.lecture = false; });
            audio.addEventListener('ended', () => {
                this.lecture = false;
                this.position = 0;
                audio.currentTime = 0;
            });
            audio.addEventListener('error', () => {
                this.chargement = false;
                this.lecture = false;
            });
        },

        /** Un seul vocal a la fois, dans tout le fil — comme WhatsApp. */
        autreLecture(detail) {
            if (detail?.id !== this.id && this.lecture) {
                this.$refs.audio.pause();
            }
        },

        basculer() {
            const audio = this.$refs.audio;

            if (this.lecture) {
                audio.pause();
                return;
            }

            window.dispatchEvent(new CustomEvent('bp-vocal-lecture', { detail: { id: this.id } }));

            if (! this.dejaLance) {
                this.dejaLance = true;
                this.chargement = true;
            }

            audio.playbackRate = this.vitesse;
            audio.play().catch(() => { this.chargement = false; });
        },

        changerVitesse() {
            const i = VITESSES.indexOf(this.vitesse);
            this.vitesse = VITESSES[(i + 1) % VITESSES.length];
            this.$refs.audio.playbackRate = this.vitesse;
        },

        get vitesseLisible() {
            return `${String(this.vitesse).replace('.', ',')}×`;
        },

        get ratio() {
            return this.duree > 0 ? Math.min(1, this.position / this.duree) : 0;
        },

        /** Pendant la lecture, le temps ecoule ; au repos, la duree totale. */
        get compteur() {
            return formaterDuree(this.dejaLance && (this.lecture || this.position > 0) ? this.position : this.duree);
        },

        barreJouee(index) {
            return (index + 0.5) / this.barres.length <= this.ratio;
        },

        /** Toucher l'onde deplace la lecture, comme glisser le curseur WhatsApp. */
        chercher(evenement) {
            if (! this.duree) return;

            const zone = evenement.currentTarget.getBoundingClientRect();
            const x = (evenement.touches?.[0]?.clientX ?? evenement.clientX) - zone.left;
            const ratio = Math.max(0, Math.min(1, x / zone.width));

            this.position = ratio * this.duree;
            this.$refs.audio.currentTime = this.position;
        },

        /** Clavier : fleches = 5 s, comme tout curseur de media. */
        clavier(evenement) {
            if (! this.duree) return;
            const pas = evenement.key === 'ArrowRight' ? 5 : (evenement.key === 'ArrowLeft' ? -5 : 0);
            if (pas === 0) return;
            evenement.preventDefault();
            this.position = Math.max(0, Math.min(this.duree, this.position + pas));
            this.$refs.audio.currentTime = this.position;
        },
    };
}
