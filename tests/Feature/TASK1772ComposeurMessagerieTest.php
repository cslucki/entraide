<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Support\Files\TypesDeFichiersAcceptes;
use Tests\TestCase;

/**
 * TASK-1772 (recette Cyril, 06/11) — LE COMPOSEUR DE LA MESSAGERIE PRIVEE.
 *
 * « Le chat composer de la messagerie privee n'est pas le meme que celui des
 * ChatLoops. Il faut qu'il soit identique. » Puis, sur les actions :
 * « un icone d'appareil photo qui appelle la camera (photo ou video), un
 * fichier qui appelle le filesystem, une image qui appelle la galerie ».
 *
 * Ces gardes mesurent les trois promesses que du rendu seul ne dit pas :
 * la pilule est bien celle du ChatLoop, les trois portes existent et visent
 * trois selecteurs DIFFERENTS, et une video est bornee plus bas qu'un fichier.
 */
class TASK1772ComposeurMessagerieTest extends TestCase
{
    private function composeur(): string
    {
        return file_get_contents(resource_path('views/livewire/message-thread.blade.php'));
    }

    /** La pilule du ChatLoop s'obtient en FOURNISSANT le slot `leading`. */
    public function test_la_messagerie_adopte_la_pilule_du_chatloop(): void
    {
        $vue = $this->composeur();

        $this->assertStringContainsString('<x-slot:leading>', $vue,
            'c\'est ce slot, et lui seul, qui fait basculer le composeur en gabarit pilule (T1769)');
        $this->assertStringContainsString('x-teleport="body"', $vue,
            'la feuille d\'actions est teleportee, comme celle du ChatLoop');
    }

    /** Trois portes, trois selecteurs differents — pas trois fois le meme. */
    public function test_les_trois_actions_visent_trois_selecteurs_distincts(): void
    {
        $vue = $this->composeur();

        $this->assertStringContainsString('$refs.cameraInput?.click()', $vue, 'l\'appareil photo');
        $this->assertStringContainsString('$refs.imageInput?.click()', $vue, 'la galerie d\'images');
        $this->assertStringContainsString('$refs.uploadInput?.click()', $vue, 'le systeme de fichiers');

        $composant = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        // La galerie ne propose QUE des images ; le champ « fichier » porte la
        // liste elargie de la Messagerie. Les confondre rendrait deux tuiles
        // identiques — le defaut que Cyril a nomme sur le menu `+` du ChatLoop.
        $this->assertMatchesRegularExpression('/x-ref="imageInput"[^>]*accept="image\/\*"/', $composant);
        $this->assertStringNotContainsString('x-ref="videoInput"', $composant,
            'la camera filme et le selecteur de fichiers prend une video deja enregistree : un troisieme champ ne sert rien');
    }

    /**
     * L'appareil photo doit ouvrir L'APPAREIL PHOTO.
     *
     * Recette Cyril du 06/11, PWA Android : avec `accept="image/*,video/*"`,
     * le bouton ouvrait la GALERIE. Chrome Android n'honore `capture` que sur
     * un `accept` d'un seul type. La video se joint par le selecteur de
     * fichiers, dont la liste elargie la contient.
     */
    public function test_l_appareil_photo_ouvre_l_appareil_photo(): void
    {
        $composant = file_get_contents(resource_path('views/components/conversation/composer.blade.php'));

        $this->assertMatchesRegularExpression(
            '/x-ref="cameraInput"[^>]*accept="image\/\*"[^>]*capture="environment"/',
            $composant,
            'un accept a deux types fait ignorer `capture` : le selecteur generique s\'ouvre'
        );
        $this->assertStringNotContainsString('image/*,video/*', $composant);

        // Et la video reste joignable : le selecteur de fichiers la propose.
        $this->assertContains('video/mp4', TypesDeFichiersAcceptes::tous());
    }

    /** 25 Mo pour une video, 50 pour un fichier, 10 pour une image. */
    public function test_une_video_est_bornee_plus_bas_qu_un_fichier(): void
    {
        $this->assertSame(25600, MessageThread::TAILLE_MAX_VIDEO_KO);
        $this->assertLessThan(MessageThread::TAILLE_MAX_FICHIER_KO, MessageThread::TAILLE_MAX_VIDEO_KO,
            'la PROD tourne sur UNE instance de 512 Mo : une video ne doit pas peser autant qu\'un fichier');
    }

    /** Une video se regarde DANS la bulle, sans la telecharger. */
    public function test_une_video_est_lue_dans_la_bulle(): void
    {
        $this->assertTrue((new Message(['file_mime' => 'video/mp4']))->fileIsPreviewable(),
            'une video doit etre apercevable, sinon aucune URL ne nourrit <video>');

        $html = $this->blade(
            '<x-conversation.file-attachment-card name="demo.mp4" mime="video/mp4" download-url="/d" preview-url="/p" />'
        )->__toString();

        $this->assertStringContainsString('data-message-video', $html);
        $this->assertStringContainsString('playsinline', $html, 'sans lui, iOS part en plein ecran et quitte la conversation');
        $this->assertStringContainsString('preload="metadata"', $html, 'ne pas precharger toutes les videos du fil');
        $this->assertStringContainsString('data-message-attachment', $html, 'la carte reste : nom, poids, telechargement');

        // Un PDF, lui, n'a pas de lecteur.
        $pdf = $this->blade(
            '<x-conversation.file-attachment-card name="demo.pdf" mime="application/pdf" download-url="/d" preview-url="/p" />'
        )->__toString();
        $this->assertStringNotContainsString('data-message-video', $pdf);
    }
}
