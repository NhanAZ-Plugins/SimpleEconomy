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
    -- #{ pair_engine
        SELECT 'sqlite' AS engine FROM sqlite_master WHERE type = 'table' AND name = 'simplesql_data';
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
        ON CONFLICT(id) DO UPDATE SET data = excluded.data, revision = excluded.revision;
    -- #}
    -- #{ delete
        -- #    :id string
        DELETE FROM simplesql_data WHERE id = :id;
    -- #}
-- #}
