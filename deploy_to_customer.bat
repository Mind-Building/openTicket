@echo off
REM ========================================
REM Ticket System - Customer Deployment Script
REM ========================================
REM Kopiert die Basis-Anwendung zu einer Kunden-Installation
REM WICHTIG: Kundenkonfigurationen und Daten bleiben erhalten!
REM ========================================

setlocal EnableDelayedExpansion

echo.
echo ========================================
echo   Ticket System - Customer Deployment
echo ========================================
echo.

REM Basis-Pfad (wo sich dieses Script befindet)
set "SOURCE_DIR=%~dp0"
set "SOURCE_DIR=%SOURCE_DIR:~0,-1%"

REM ========================================
REM BACKUP-FRAGE (vor allem anderen)
REM ========================================
set "DO_BACKUP=N"
set /p DO_BACKUP="Backup vor dem Deployment erstellen? (J/N) [Standard: N]: "
if /i "!DO_BACKUP!"=="J" (
    echo   Backup wird erstellt.
) else (
    set "DO_BACKUP=N"
    echo   Kein Backup.
)
echo.

REM ========================================
REM KUNDEN-KONFIGURATION
REM Echte Kunden in deploy_customers.local.bat eintragen
REM (nicht im Git, Vorlage: deploy_customers.example.bat)
REM ========================================
set "CUSTOMER_1_NAME=kunde_a"
set "CUSTOMER_1_DIR=d:\xampp\htdocs\ticket_kunde_a"
set "CUSTOMER_2_NAME=kunde_b"
set "CUSTOMER_2_DIR=d:\xampp\htdocs\ticket_kunde_b"
if exist "%SOURCE_DIR%\deploy_customers.local.bat" call "%SOURCE_DIR%\deploy_customers.local.bat"

echo Verfuegbare Kunden:
echo   1) %CUSTOMER_1_NAME% (%CUSTOMER_1_DIR%)
echo   2) %CUSTOMER_2_NAME% (%CUSTOMER_2_DIR%)
echo   3) Benutzerdefiniert
echo   4) Alle Kunden (automatisch)
echo.

set "CUSTOMER_CHOICE=4"
set /p CUSTOMER_CHOICE="Waehlen Sie einen Kunden (1-4) [Standard: 4]: "

if "%CUSTOMER_CHOICE%"=="1" (
    set "TARGET_DIR=%CUSTOMER_1_DIR%"
    set "CUSTOMER_NAME=%CUSTOMER_1_NAME%"
    goto :SINGLE_DEPLOY
)
if "%CUSTOMER_CHOICE%"=="2" (
    set "TARGET_DIR=%CUSTOMER_2_DIR%"
    set "CUSTOMER_NAME=%CUSTOMER_2_NAME%"
    goto :SINGLE_DEPLOY
)
if "%CUSTOMER_CHOICE%"=="3" (
    set /p "TARGET_DIR=Geben Sie den vollstaendigen Zielpfad ein: "
    set "CUSTOMER_NAME=Benutzerdefiniert"
    goto :SINGLE_DEPLOY
)
if "%CUSTOMER_CHOICE%"=="4" (
    goto :ALL_DEPLOY
)

echo FEHLER: Ungueltige Auswahl!
pause
exit /b 1

REM ========================================
REM Einzelnen Kunden deployen
REM ========================================
:SINGLE_DEPLOY
echo.
echo Gewaehlt: %CUSTOMER_NAME%
echo Quelle:   %SOURCE_DIR%
echo Ziel:     %TARGET_DIR%
echo.

set "CONFIRM=J"
set /p CONFIRM="Moechten Sie fortfahren? (J/N) [Standard: J]: "
if /i not "%CONFIRM%"=="J" (
    echo Abgebrochen.
    pause
    exit /b 0
)

call :DO_DEPLOY "%TARGET_DIR%" "%CUSTOMER_NAME%" "%DO_BACKUP%"

echo.
echo ========================================
echo   DEPLOYMENT ABGESCHLOSSEN!
echo ========================================
echo.
echo Kunde:  %CUSTOMER_NAME%
echo Ziel:   %TARGET_DIR%
echo.
echo Dateien die NICHT kopiert wurden (kundenspezifisch):
echo   - .env (Konfiguration)
echo   - /public/uploads (Kunden-Dateien)
echo.
pause
exit /b 0

REM ========================================
REM Alle Kunden deployen
REM ========================================
:ALL_DEPLOY
echo.
echo Alle konfigurierten Kunden:
echo   - %CUSTOMER_1_NAME%  (%CUSTOMER_1_DIR%)
echo   - %CUSTOMER_2_NAME%  (%CUSTOMER_2_DIR%)
echo.
set "CONFIRM=J"
set /p CONFIRM="Wirklich fuer ALLE Kunden deployen? (J/N) [Standard: J]: "
if /i not "%CONFIRM%"=="J" (
    echo Abgebrochen.
    pause
    exit /b 0
)

echo.
echo ########################################
echo #  [1/2] Deploye: %CUSTOMER_1_NAME%
echo ########################################
call :DO_DEPLOY "%CUSTOMER_1_DIR%" "%CUSTOMER_1_NAME%" "%DO_BACKUP%"
if errorlevel 1 (
    echo.
    echo FEHLER beim Deployment fuer %CUSTOMER_1_NAME%!
    echo Deployment fuer %CUSTOMER_2_NAME% wird trotzdem fortgesetzt...
    echo.
)

echo.
echo ########################################
echo #  [2/2] Deploye: %CUSTOMER_2_NAME%
echo ########################################
call :DO_DEPLOY "%CUSTOMER_2_DIR%" "%CUSTOMER_2_NAME%" "%DO_BACKUP%"
if errorlevel 1 (
    echo.
    echo FEHLER beim Deployment fuer %CUSTOMER_2_NAME%!
    echo.
)

echo.
echo ========================================
echo   ALLE DEPLOYMENTS ABGESCHLOSSEN!
echo ========================================
echo.
echo   - %CUSTOMER_1_NAME%  ^>^>  %CUSTOMER_1_DIR%
echo   - %CUSTOMER_2_NAME%  ^>^>  %CUSTOMER_2_DIR%
echo.
echo Dateien die NICHT kopiert wurden (kundenspezifisch):
echo   - .env (Konfiguration)
echo   - /public/uploads (Kunden-Dateien)
echo.
pause
exit /b 0

REM ========================================
REM SUBROUTINE: Einzelnes Deployment
REM Parameter: %1=Zielpfad, %2=Kundenname
REM ========================================
:DO_DEPLOY
setlocal EnableDelayedExpansion

set "TARGET_DIR=%~1"
set "CUSTOMER_NAME=%~2"
set "DO_BACKUP=%~3"

REM ----------------------------------------
REM [1/6] Zielverzeichnis prüfen
REM ----------------------------------------
echo.
echo [1/6] Pruefe Zielverzeichnis...

if not exist "%TARGET_DIR%" (
    echo       Zielverzeichnis existiert nicht. Erstelle neue Installation...
    mkdir "%TARGET_DIR%"
    set "FIRST_INSTALL=1"
) else (
    echo       Zielverzeichnis gefunden - Update-Modus
    set "FIRST_INSTALL=0"
)

REM ----------------------------------------
REM [2/6] Backup erstellen (optional, bei Update)
REM ----------------------------------------
echo.
if "!FIRST_INSTALL!"=="0" (
    if /i "!DO_BACKUP!"=="J" (
        echo [2/6] Erstelle Backup...

        set "BACKUP_DIR=%TARGET_DIR%_backup_%date:~-4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%"
        set "BACKUP_DIR=!BACKUP_DIR: =0!"
        echo       Backup-Verzeichnis: !BACKUP_DIR!

        if exist "%TARGET_DIR%\app" (
            mkdir "!BACKUP_DIR!\app" 2>nul
            xcopy "%TARGET_DIR%\app" "!BACKUP_DIR!\app" /E /I /Y /Q >nul
        )
        if exist "%TARGET_DIR%\public" (
            mkdir "!BACKUP_DIR!\public" 2>nul
            xcopy "%TARGET_DIR%\public" "!BACKUP_DIR!\public" /E /I /Y /Q >nul
        )

        echo       Backup erstellt!
    ) else (
        echo [2/6] Backup uebersprungen (nicht angefordert^)
    )
) else (
    echo [2/6] Backup uebersprungen (Erstinstallation^)
)

REM ----------------------------------------
REM [3/6] Ordnerstruktur erstellen
REM ----------------------------------------
echo.
echo [3/6] Erstelle Ordnerstruktur...

if not exist "%TARGET_DIR%\app"    mkdir "%TARGET_DIR%\app"
if not exist "%TARGET_DIR%\public" mkdir "%TARGET_DIR%\public"

if not exist "%TARGET_DIR%\database" mkdir "%TARGET_DIR%\database"

if not exist "%TARGET_DIR%\public\uploads"            mkdir "%TARGET_DIR%\public\uploads"
if not exist "%TARGET_DIR%\public\uploads\tickets"    mkdir "%TARGET_DIR%\public\uploads\tickets"
if not exist "%TARGET_DIR%\public\uploads\vouchers"   mkdir "%TARGET_DIR%\public\uploads\vouchers"
if not exist "%TARGET_DIR%\public\uploads\seat_plans" mkdir "%TARGET_DIR%\public\uploads\seat_plans"

echo       Ordnerstruktur bereit

REM ----------------------------------------
REM [4/6] Code-Dateien kopieren
REM ----------------------------------------
echo.
echo [4/6] Kopiere Anwendungs-Dateien...

echo       - app\ (PHP Code)
xcopy "%SOURCE_DIR%\app" "%TARGET_DIR%\app" /E /I /Y /Q >nul

echo       - public\ (ohne uploads)
xcopy "%SOURCE_DIR%\public\*.php"  "%TARGET_DIR%\public\" /Y /Q >nul
xcopy "%SOURCE_DIR%\public\*.html" "%TARGET_DIR%\public\" /Y /Q 2>nul

if exist "%SOURCE_DIR%\public\api\" (
    echo       - public\api\
    xcopy "%SOURCE_DIR%\public\api" "%TARGET_DIR%\public\api" /E /I /Y /Q >nul
)
if exist "%SOURCE_DIR%\public\admin\" (
    echo       - public\admin\
    xcopy "%SOURCE_DIR%\public\admin" "%TARGET_DIR%\public\admin" /E /I /Y /Q >nul
)
if exist "%SOURCE_DIR%\public\presales\" (
    echo       - public\presales\
    xcopy "%SOURCE_DIR%\public\presales" "%TARGET_DIR%\public\presales" /E /I /Y /Q >nul
)
if exist "%SOURCE_DIR%\public\libs\" (
    echo       - public\libs\ ^(Frontend-Bibliotheken^)
    xcopy "%SOURCE_DIR%\public\libs" "%TARGET_DIR%\public\libs" /E /I /Y /Q >nul
) else (
    echo       WARNUNG: public\libs\ nicht gefunden!
)
if exist "%SOURCE_DIR%\vendor\" (
    echo       - vendor\ ^(PHP Dependencies, ohne Dev-Pakete^)
    echo         Entferne Dev-Abhaengigkeiten fuer Deployment...
    call composer install --no-dev --optimize-autoloader -q --working-dir="%SOURCE_DIR%" 2>nul
    xcopy "%SOURCE_DIR%\vendor" "%TARGET_DIR%\vendor" /E /I /Y /Q >nul
    echo         Stelle Dev-Abhaengigkeiten wieder her...
    call composer install --optimize-autoloader -q --working-dir="%SOURCE_DIR%" 2>nul
)
if exist "%SOURCE_DIR%\composer.json" (
    echo       - composer.json
    copy "%SOURCE_DIR%\composer.json" "%TARGET_DIR%\composer.json" /Y >nul
)
if exist "%SOURCE_DIR%\composer.lock" (
    echo       - composer.lock
    copy "%SOURCE_DIR%\composer.lock" "%TARGET_DIR%\composer.lock" /Y >nul
)
if exist "%SOURCE_DIR%\package.json" (
    echo       - package.json
    copy "%SOURCE_DIR%\package.json" "%TARGET_DIR%\package.json" /Y >nul
)
if exist "%SOURCE_DIR%\database" (
    echo       - database\ ^(Schema und Seeds^)
    xcopy "%SOURCE_DIR%\database" "%TARGET_DIR%\database" /E /I /Y /Q >nul
)
if exist "%SOURCE_DIR%\docs\manuals" (
    echo       - docs\manuals\ ^(PDF-Handbuecher^)
    xcopy "%SOURCE_DIR%\docs\manuals" "%TARGET_DIR%\docs\manuals" /E /I /Y /Q >nul
)
REM setup.php und migrate_*.php werden NICHT deployt (Sicherheitsrisiko)
echo       HINWEIS: setup.php und migrate_*.php werden nicht kopiert ^(Sicherheit^)
if exist "%SOURCE_DIR%\.htaccess.production" (
    if not exist "%TARGET_DIR%\.htaccess" (
        echo       - .htaccess (Root, Produktion: RewriteBase /)
        copy "%SOURCE_DIR%\.htaccess.production" "%TARGET_DIR%\.htaccess" >nul
    )
) else if exist "%SOURCE_DIR%\.htaccess" (
    if not exist "%TARGET_DIR%\.htaccess" (
        echo       - .htaccess (Root)
        copy "%SOURCE_DIR%\.htaccess" "%TARGET_DIR%\.htaccess" >nul
    )
)

echo       Code-Dateien kopiert

REM ----------------------------------------
REM [5/6] Konfiguration (nur Erstinstallation)
REM ----------------------------------------
echo.
echo [5/6] Pruefe Konfiguration...

if "!FIRST_INSTALL!"=="1" (
    if exist "%SOURCE_DIR%\.env.example" (
        if not exist "%TARGET_DIR%\.env" (
            echo       - .env Vorlage erstellt
            copy "%SOURCE_DIR%\.env.example" "%TARGET_DIR%\.env" >nul
            echo       WICHTIG: Bitte .env konfigurieren!
        )
    )
    if exist "%SOURCE_DIR%\public\.htaccess" (
        if not exist "%TARGET_DIR%\public\.htaccess" (
            echo       - public/.htaccess kopiert
            copy "%SOURCE_DIR%\public\.htaccess" "%TARGET_DIR%\public\.htaccess" >nul
        )
    )

    (
        echo # %CUSTOMER_NAME% - Ticket System Installation
        echo.
        echo Installation: %date% %time%
        echo Quelle: %SOURCE_DIR%
        echo.
        echo ## Naechste Schritte:
        echo.
        echo ### 1. Konfiguration
        echo    - Bearbeite .env Datei
        echo    - Passe Datenbank-Pfad an
        echo    - Trage PayPal-Credentials ein
        echo    - Setze Email-Konfiguration
        echo.
        echo ### 2. Datenbank initialisieren
        echo    Option A: Browser oeffnen: http://localhost/ticket_%CUSTOMER_NAME%/setup.php
        echo    Option B: Datenbank manuell aus /database/seeds/ importieren
        echo.
        echo ### 3. Web-Server konfigurieren
        echo    - Apache Virtual Host auf public/ Verzeichnis zeigen lassen
        echo    - ODER .htaccess im Root verwenden
        echo.
        echo ### 4. Testen
        echo    - Admin-Login: siehe Datenbank-Seeds
        echo    - Funktionen pruefen
        echo.
    ) > "%TARGET_DIR%\README_KUNDE.txt"

    echo       Erstinstallation abgeschlossen
) else (
    echo       Update - Kundenkonfiguration beibehalten
)

REM ----------------------------------------
REM [6/6] Zusammenfassung
REM ----------------------------------------
echo.
echo [6/6] %CUSTOMER_NAME% - Deployment abgeschlossen!
echo       Ziel:   %TARGET_DIR%
if "!FIRST_INSTALL!"=="0" (
    if /i "!DO_BACKUP!"=="J" (
        echo       Backup: !BACKUP_DIR!
    )
)
if "!FIRST_INSTALL!"=="1" (
    echo.
    echo       WICHTIG ^(Erstinstallation^):
    echo         1. Konfiguriere %TARGET_DIR%\.env
    echo         2. Oeffne http://localhost/ticket_%CUSTOMER_NAME%/setup.php
    echo         3. Setze Document Root auf: %TARGET_DIR%\public
) else (
    echo.
    echo       HINWEIS ^(Update^): Datenbank aktualisieren:
    echo         php %TARGET_DIR%\database\update_database.php
    echo         oder im Admin unter Einstellungen - Datenbank-Update
)

endlocal
exit /b 0
