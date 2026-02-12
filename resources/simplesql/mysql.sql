-- #!mysql
-- #{ simplesql
    -- #{ init
        CREATE TABLE IF NOT EXISTS simplesql_data (
            id VARCHAR(128) NOT NULL PRIMARY KEY,
            data LONGTEXT NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 0
        );
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
    -- #{ delete
        -- #    :id string
        DELETE FROM simplesql_data WHERE id = :id;
    -- #}
-- #}
