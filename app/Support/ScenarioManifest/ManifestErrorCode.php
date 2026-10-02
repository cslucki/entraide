<?php

namespace App\Support\ScenarioManifest;

/**
 * Codes d'erreur du Scenario Manifest V1 (TASK-1641).
 *
 * Liste FERMEE : ce sont exactement les "Codes minimaux V1" de la section 9.4
 * de `TODO/SPECS/260920-11h10-CDC-scenario-manifest.md`. Le Validator n'en
 * invente aucun autre : un producteur d'IA qui connait la seule spec doit
 * pouvoir anticiper le code retourne. Ajouter un code ici est un changement de
 * contrat public, pas un detail d'implementation.
 */
enum ManifestErrorCode: string
{
    case INVALID_JSON = 'INVALID_JSON';
    case INVALID_UTF8 = 'INVALID_UTF8';
    case PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    case MAX_DEPTH_EXCEEDED = 'MAX_DEPTH_EXCEEDED';
    case UNKNOWN_SCHEMA_VERSION = 'UNKNOWN_SCHEMA_VERSION';
    case MISSING_FIELD = 'MISSING_FIELD';
    case UNKNOWN_FIELD = 'UNKNOWN_FIELD';
    case INVALID_TYPE = 'INVALID_TYPE';
    case INVALID_FORMAT = 'INVALID_FORMAT';
    case INVALID_ENUM = 'INVALID_ENUM';
    case VALUE_TOO_LONG = 'VALUE_TOO_LONG';
    case LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';
    case DUPLICATE_KEY = 'DUPLICATE_KEY';
    case DUPLICATE_COMPOSITE_KEY = 'DUPLICATE_COMPOSITE_KEY';
    case REFERENCE_NOT_FOUND = 'REFERENCE_NOT_FOUND';
    case REFERENCE_WRONG_SCOPE = 'REFERENCE_WRONG_SCOPE';
    case REFERENCE_CYCLE = 'REFERENCE_CYCLE';
    case OWNER_MEMBERSHIP_MISMATCH = 'OWNER_MEMBERSHIP_MISMATCH';
    case TIMELINE_INCONSISTENT = 'TIMELINE_INCONSISTENT';
    case UNSAFE_CONTENT = 'UNSAFE_CONTENT';
    case AVATAR_NOT_FOUND = 'AVATAR_NOT_FOUND';
    case FORBIDDEN_PROPERTY = 'FORBIDDEN_PROPERTY';
    case FORBIDDEN_OBJECT = 'FORBIDDEN_OBJECT';
    case TENANT_TARGET_FORBIDDEN = 'TENANT_TARGET_FORBIDDEN';
}
