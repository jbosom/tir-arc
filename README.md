# ACTLL · Gestió de socis

Aplicació web de demostració per gestionar clubs, arquers, altes, renovacions, quotes i importacions massives de l’Associació Catalana de Tir Lliure.

La demo utilitza PHP 8.2 o superior, JavaScript i CSS sense frameworks, PDO i SQLite. No necessita MySQL ni serveis externs.

## Posada en marxa

Requisits: PHP amb les extensions `pdo_sqlite` i `sqlite3`.

```powershell
php database/init.php
php -S 127.0.0.1:8080 -t public
```

Obre `http://127.0.0.1:8080` al navegador.

Comptes inicials:

- Administració: `admin@actll.test` / `Actll2026!`
- Club de prova: `club@actll.test` / `Actll2026!`

Cal canviar aquestes contrasenyes abans de publicar l’aplicació. `database/init.php` copia automàticament `config/config.example.php` a `config/config.php` la primera vegada.

## Funcions disponibles

- Autenticació segura i rols d’administració i club.
- Aïllament de dades: un club només consulta els seus arquers, inscripcions, quotes i importacions.
- Gestió de clubs, responsables, temporades i imports.
- Alta individual amb validació de menors i registre de l’acceptació de privacitat.
- Renovació que conserva el número de soci.
- Revisió administrativa amb aprovació, rebuig i observacions.
- Número de soci automàtic en aprovar la primera sol·licitud.
- Gestió i verificació de pagaments.
- Importació CSV amb plantilla, previsualització, detecció de duplicats, errors per fila i confirmació transaccional.
- Exportació CSV d’arquers i inscripcions.
- Auditoria d’accions rellevants.
- Text de privacitat versionat i configurable.
- Interfície responsive en català.

La plantilla es troba a `public/templates/plantilla_inscripcions.csv` i també es pot descarregar des de la pantalla d’importació.

## Arquitectura

- `public/index.php`: encaminament, casos d’ús i pantalles.
- `src/bootstrap.php`: connexió PDO, sessió, permisos, CSRF, escapament i utilitats compartides.
- `public/assets`: estils i comportament de la interfície.
- `database/schema.sql`: esquema SQLite normalitzat, índexs, restriccions i claus foranes.
- `database/init.php`: inicialització idempotent i dades de demostració.
- `database/create_admin.php`: creació segura d’administradors des de consola.
- `storage/actll.sqlite`: base local generada; queda exclosa de Git.
- `tests/smoke.php`: comprovacions mínimes de l’entorn i la base de dades.

## Verificació

```powershell
php -l public/index.php
php -l src/bootstrap.php
php tests/smoke.php
```

## Decisions i supòsits

- SQLite s’utilitza per facilitar la demo. PDO permet substituir la capa de persistència més endavant, però l’esquema haurà d’adaptar-se al motor escollit.
- El club confirma que ha informat l’arquer i disposa de base jurídica per comunicar-ne les dades. El text és configurable i no substitueix la revisió legal.
- Una coincidència per document o per nom, cognoms i data de naixement es tracta com un arquer existent durant la importació.
- El canvi de club d’un arquer existent només es fa efectiu quan l’administració aprova la renovació; la importació no transfereix la fitxa automàticament.
- Només pot existir una inscripció d’un arquer per temporada, independentment de l’estat, per evitar sol·licituds contradictòries.
- Els fitxers CSV no es conserven: només es desa temporalment la previsualització validada a la base de dades.

## Abans de producció

1. Revisar els textos, la base jurídica, els terminis de conservació i els contractes o acords entre ACTLL i clubs amb assessorament especialitzat.
2. Canviar totes les credencials inicials i activar HTTPS i cookies segures a `config/config.php`.
3. Moure la base SQLite fora de qualsevol directori públic, restringir-ne els permisos i programar còpies de seguretat xifrades.
4. Configurar un servidor web de producció, límits de peticions, capçaleres de seguretat, monitoratge i rotació de registres.
5. Afegir recuperació de contrasenya, política de contrasenyes, MFA per a administració i bloqueig de força bruta.
6. Executar proves funcionals i de seguretat amb dades fictícies, incloent concurrència d’importacions.
7. Per a un volum elevat o diversos processos d’escriptura simultanis, migrar a PostgreSQL o MySQL.

