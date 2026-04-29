@echo off
chcp 65001 >nul
echo ========================================
echo   Installation Entraide - Troc de services
echo ========================================
echo.

REM --- Vérifier que Composer est installé ---
where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Composer n'est pas installé ou pas dans le PATH.
    echo Téléchargez-le sur https://getcomposer.org/
    pause
    exit /b 1
)

REM --- Installer les dépendances PHP ---
echo [1/5] Installation des dépendances PHP (composer install)...
composer install --no-interaction --prefer-dist
if %errorlevel% neq 0 (
    echo [ERREUR] composer install a échoué.
    pause
    exit /b 1
)

REM --- Copier .env ---
echo [2/5] Création du fichier .env...
if not exist .env (
    copy .env.example .env
    echo Fichier .env créé depuis .env.example
) else (
    echo Fichier .env déjà présent, conservé.
)

REM --- Générer la clé d'application ---
echo [3/5] Génération de la clé Laravel...
php artisan key:generate

REM --- Créer la base de données SQLite si besoin ---
echo [4/5] Préparation de la base de données...
if not exist database\database.sqlite (
    type nul > database\database.sqlite
    echo Fichier SQLite créé : database/database.sqlite
)

REM --- Migrations + Seeders ---
php artisan migrate --seed --force
if %errorlevel% neq 0 (
    echo [ERREUR] Les migrations ont échoué. Vérifiez votre config .env.
    pause
    exit /b 1
)

REM --- Lien storage ---
echo [5/5] Création du lien public/storage...
php artisan storage:link

echo.
echo ========================================
echo   Installation terminée avec succès !
echo ========================================
echo.
echo Accès : http://localhost/entraide/public
echo.
echo Compte test : test@example.com / password
echo Compte test : alice@example.com / password
echo.
echo Pour démarrer le serveur de dev intégré :
echo   php artisan serve
echo   puis ouvrir http://localhost:8000
echo.
pause
