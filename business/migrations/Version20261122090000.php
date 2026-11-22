<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demo business system schema (synthetic wholesaler data) and the reporting views granted to business_reader.
 * The reader never sees tables: only v_* views, which hide non-permitted fields (purchase price, supplier)
 * and apply the inclusion-date and cancellation rules (RPT-SALES-1).
 */
final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Business schema, reporting views and reader grants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE manufacturer (
                id smallint PRIMARY KEY,
                code text NOT NULL UNIQUE,
                name text NOT NULL,
                aliases text[] NOT NULL DEFAULT '{}',
                active boolean NOT NULL DEFAULT true
            )
            SQL);
        $this->addSql('CREATE TABLE supplier (id smallint PRIMARY KEY, name text NOT NULL)');
        $this->addSql(<<<'SQL'
            CREATE TABLE part (
                id integer PRIMARY KEY,
                manufacturer_id smallint NOT NULL REFERENCES manufacturer (id),
                supplier_id smallint NOT NULL REFERENCES supplier (id),
                part_number text NOT NULL UNIQUE,
                name text NOT NULL,
                category text NOT NULL,
                unit text NOT NULL,
                list_price_net numeric(14,2) NOT NULL CHECK (list_price_net >= 0),
                purchase_price_net numeric(14,2) NOT NULL CHECK (purchase_price_net >= 0),
                active boolean NOT NULL DEFAULT true
            )
            SQL);
        $this->addSql('CREATE TABLE sales_rep (id smallint PRIMARY KEY, code text NOT NULL UNIQUE, name text NOT NULL)');
        $this->addSql(<<<'SQL'
            CREATE TABLE customer (
                id integer PRIMARY KEY,
                code text NOT NULL UNIQUE,
                name text NOT NULL,
                city text NOT NULL,
                active boolean NOT NULL DEFAULT true
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE customer_assignment (
                customer_id integer PRIMARY KEY REFERENCES customer (id),
                sales_rep_id smallint NOT NULL REFERENCES sales_rep (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_document (
                id integer PRIMARY KEY,
                number text NOT NULL UNIQUE,
                kind text NOT NULL CHECK (kind IN ('invoice', 'adjustment')),
                status text NOT NULL CHECK (status IN ('issued', 'canceled')),
                customer_id integer NOT NULL REFERENCES customer (id),
                sale_date date,
                issue_date date NOT NULL,
                corrects_document_id integer REFERENCES sales_document (id),
                CHECK ((kind = 'invoice' AND sale_date IS NOT NULL AND corrects_document_id IS NULL)
                    OR (kind = 'adjustment' AND corrects_document_id IS NOT NULL))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE sales_line (
                id integer PRIMARY KEY,
                document_id integer NOT NULL REFERENCES sales_document (id),
                line_no smallint NOT NULL,
                part_id integer NOT NULL REFERENCES part (id),
                quantity numeric(12,3) NOT NULL,
                net_value numeric(14,2) NOT NULL,
                UNIQUE (document_id, line_no)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE discount_policy (
                code text NOT NULL,
                version text NOT NULL,
                valid_from date NOT NULL,
                tiers jsonb NOT NULL,
                fictional boolean NOT NULL DEFAULT true,
                PRIMARY KEY (code, version)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE seed_meta (
                id boolean PRIMARY KEY DEFAULT true CHECK (id),
                data_version text NOT NULL,
                random_seed integer NOT NULL,
                reference_date date NOT NULL,
                generated_at timestamptz NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX sales_document_customer_idx ON sales_document (customer_id)');
        $this->addSql('CREATE INDEX sales_line_document_idx ON sales_line (document_id)');

        // Reporting views (the only objects business_reader may read).
        $this->addSql(<<<'SQL'
            CREATE VIEW v_sales_line AS
            SELECT l.id AS line_id,
                   d.number AS document_number,
                   d.kind AS document_kind,
                   CASE d.kind WHEN 'invoice' THEN d.sale_date ELSE d.issue_date END AS inclusion_date,
                   c.code AS customer_code,
                   c.name AS customer_name,
                   r.code AS sales_rep_code,
                   p.part_number,
                   m.code AS manufacturer_code,
                   m.name AS manufacturer_name,
                   p.category,
                   l.quantity,
                   l.net_value
              FROM sales_line l
              JOIN sales_document d ON d.id = l.document_id
              JOIN customer c ON c.id = d.customer_id
              LEFT JOIN customer_assignment a ON a.customer_id = c.id
              LEFT JOIN sales_rep r ON r.id = a.sales_rep_id
              JOIN part p ON p.id = l.part_id
              JOIN manufacturer m ON m.id = p.manufacturer_id
             WHERE d.status = 'issued'
            SQL);
        $this->addSql(<<<'SQL'
            CREATE VIEW v_customer AS
            SELECT c.code, c.name, c.city, c.active, r.code AS sales_rep_code
              FROM customer c
              LEFT JOIN customer_assignment a ON a.customer_id = c.id
              LEFT JOIN sales_rep r ON r.id = a.sales_rep_id
            SQL);
        $this->addSql('CREATE VIEW v_sales_rep AS SELECT code, name FROM sales_rep');
        $this->addSql('CREATE VIEW v_manufacturer AS SELECT code, name, aliases, active FROM manufacturer');
        $this->addSql(<<<'SQL'
            CREATE VIEW v_part_catalog AS
            SELECT m.code AS manufacturer_code, m.name AS manufacturer_name, p.part_number, p.name, p.category, p.unit,
                   p.list_price_net, p.active
              FROM part p
              JOIN manufacturer m ON m.id = p.manufacturer_id
            SQL);
        $this->addSql('CREATE VIEW v_discount_policy AS SELECT code, version, valid_from, tiers, fictional FROM discount_policy');
        $this->addSql('CREATE VIEW v_seed_meta AS SELECT data_version, random_seed, reference_date, generated_at FROM seed_meta');
        $this->addSql('GRANT SELECT ON v_sales_line, v_customer, v_sales_rep, v_manufacturer, v_part_catalog, v_discount_policy, v_seed_meta TO business_reader');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW v_seed_meta, v_discount_policy, v_part_catalog, v_manufacturer, v_sales_rep, v_customer, v_sales_line');
        $this->addSql('DROP TABLE seed_meta, discount_policy, sales_line, sales_document, customer_assignment, customer, sales_rep, part, supplier, manufacturer');
    }
}
