<?php

namespace App\Support\Notifications;

use RuntimeException;

/**
 * TASK-1372 — un `event_id` deja utilise pour une emission DIFFERENTE.
 *
 * ## Pourquoi une classe plutot qu'un `RuntimeException` nu
 *
 * `Illuminate\Database\QueryException` descend de `PDOException`, qui descend de
 * `RuntimeException`. Un `catch (RuntimeException)` autour d'une requete avale
 * donc silencieusement un interblocage, une connexion perdue ou une transaction
 * PostgreSQL abandonnee — et les fait passer pour un conflit d'emission.
 *
 * Le rattrapage de rejeu a besoin de distinguer « cette ligne ne correspond
 * pas » d'un incident de base. Un type dedie est la seule facon de le faire sans
 * deviner.
 */
class NotificationEmissionConflict extends RuntimeException {}
