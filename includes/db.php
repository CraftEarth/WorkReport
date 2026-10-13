<?php

require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(STORAGE_PATH)) {
        mkdir(STORAGE_PATH, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA foreign_keys = ON;');

    initialize_database($pdo);

    return $pdo;
}

function initialize_database(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_date TEXT NOT NULL,
            shift TEXT,
            department TEXT,
            machine TEXT,
            title TEXT,
            original_text TEXT NOT NULL,
            polished_text TEXT,
            status TEXT NOT NULL DEFAULT 'draft',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finalized_at TEXT,
            report_hash TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS people (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            job_title TEXT,
            department TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_people (
            report_id INTEGER NOT NULL,
            person_id INTEGER NOT NULL,
            role TEXT,
            PRIMARY KEY (report_id, person_id),
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,
            FOREIGN KEY (person_id)
                REFERENCES people(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS places (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            place_type TEXT
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_places (
            report_id INTEGER NOT NULL,
            place_id INTEGER NOT NULL,
            PRIMARY KEY (report_id, place_id),
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,
            FOREIGN KEY (place_id)
                REFERENCES places(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_categories (
            report_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            PRIMARY KEY (report_id, category_id),
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,
            FOREIGN KEY (category_id)
                REFERENCES categories(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            event_time TEXT,
            category TEXT,
            description TEXT NOT NULL,
            location TEXT,
            outcome TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            stored_filename TEXT NOT NULL,
            file_hash TEXT,
            uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS amendments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            reason TEXT,
            amendment_text TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_analysis (
            report_id INTEGER PRIMARY KEY,
            analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS person_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL UNIQUE COLLATE NOCASE,
            person_id INTEGER NOT NULL,
            FOREIGN KEY (person_id)
                REFERENCES people(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS place_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias TEXT NOT NULL UNIQUE COLLATE NOCASE,
            place_id INTEGER NOT NULL,
            FOREIGN KEY (place_id)
                REFERENCES places(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS category_keywords (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            keyword TEXT NOT NULL UNIQUE COLLATE NOCASE,
            category_id INTEGER NOT NULL,
            FOREIGN KEY (category_id)
                REFERENCES categories(id)
                ON DELETE CASCADE
        );
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_reports_date
        ON reports(report_date);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_reports_status
        ON reports(status);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_events_report
        ON events(report_id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_report_people_report
        ON report_people(report_id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_report_places_report
        ON report_places(report_id);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_report_categories_report
        ON report_categories(report_id);
    ");
}