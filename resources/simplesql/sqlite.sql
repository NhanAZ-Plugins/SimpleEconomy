-- #!sqlite
-- #{ simplesql
    -- #{ init
        CREATE TABLE IF NOT EXISTS simplesql_data (
            id TEXT NOT NULL PRIMARY KEY,
            data TEXT NOT NULL,
            revision INTEGER NOT NULL DEFAULT 0
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
        ON CONFLICT(id) DO UPDATE SET data = excluded.data, revision = excluded.revision;
    -- #}
    -- #{ delete
        -- #    :id string
        DELETE FROM simplesql_data WHERE id = :id;
    -- #}
-- #}
