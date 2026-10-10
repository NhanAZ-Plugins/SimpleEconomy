-- #!mysql
-- #{ simplesql
    -- #{ init
        CREATE TABLE IF NOT EXISTS simplesql_data (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data LONGTEXT NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB;
    -- #}
    -- #{ load
        -- #    :id string
        SELECT data, revision FROM simplesql_data WHERE id = :id;
    -- #}
    -- #{ save
        -- #    :id string
        -- #    :data string
        -- #    :revision int
        INSERT INTO simplesql_data (id, data, revision) VALUES (:id, :data, :revision)
        ON DUPLICATE KEY UPDATE data = VALUES(data), revision = VALUES(revision);
    -- #}
    -- #{ pair_engine
        SELECT ENGINE AS engine FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'simplesql_data';
    -- #}
    -- #{ save_pair
        -- #    :first_id string
        -- #    :first_data string
        -- #    :first_revision int
        -- #    :second_id string
        -- #    :second_data string
        -- #    :second_revision int
        INSERT INTO simplesql_data (id, data, revision) VALUES
            (:first_id, :first_data, :first_revision),
            (:second_id, :second_data, :second_revision)
        ON DUPLICATE KEY UPDATE data = VALUES(data), revision = VALUES(revision);
    -- #}
    -- #{ delete
        -- #    :id string
        DELETE FROM simplesql_data WHERE id = :id;
    -- #}
-- #}
