<?php

namespace justinholtweb\sevvies\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\sevvies\db\Table;

/**
 * Install migration.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::CREDITS);
        $this->dropTableIfExists(Table::INVOICES);
        $this->dropTableIfExists(Table::CONTACTS);

        return true;
    }

    private function createTables(): void
    {
        // One row per Commerce order. The unique index on orderId is the promise
        // that an order can never be invoiced twice.
        $this->createTable(Table::INVOICES, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'sevdeskId' => $this->integer()->null(),
            'invoiceNumber' => $this->string(64)->null(),
            'invoiceType' => $this->string(8)->notNull()->defaultValue('RE'),
            'sevdeskStatus' => $this->string(8)->null(),
            'state' => $this->string(32)->notNull()->defaultValue('pending'),
            'taxRule' => $this->string(8)->null(),
            'taxType' => $this->string(16)->null(),
            'taxReason' => $this->string(255)->null(),
            'currency' => $this->string(8)->null(),
            'expectedGross' => $this->decimal(14, 4)->null(),
            'sumNet' => $this->decimal(14, 4)->null(),
            'sumTax' => $this->decimal(14, 4)->null(),
            'sumGross' => $this->decimal(14, 4)->null(),
            'reconciled' => $this->boolean()->notNull()->defaultValue(false),
            'contactId' => $this->integer()->null(),
            'payloadHash' => $this->string(64)->null(),
            'payload' => $this->longText()->null(),
            'lastError' => $this->text()->null(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'sentAt' => $this->dateTime()->null(),
            'bookedAt' => $this->dateTime()->null(),
            'pdfAssetId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Craft/Commerce customer <-> sevDesk contact.
        $this->createTable(Table::CONTACTS, [
            'id' => $this->primaryKey(),
            'customerKey' => $this->string(190)->notNull(),
            'userId' => $this->integer()->null(),
            'email' => $this->string(255)->null(),
            'sevdeskId' => $this->integer()->notNull(),
            'customerNumber' => $this->string(64)->null(),
            'isOrganisation' => $this->boolean()->notNull()->defaultValue(false),
            'vatId' => $this->string(32)->null(),
            'addressHash' => $this->string(64)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Refunds mirrored into sevDesk as credit notes. Unique on (orderId, refundKey).
        $this->createTable(Table::CREDITS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'refundKey' => $this->string(190)->notNull(),
            'sevdeskId' => $this->integer()->null(),
            'creditNoteNumber' => $this->string(64)->null(),
            'amount' => $this->decimal(14, 4)->null(),
            'currency' => $this->string(8)->null(),
            'state' => $this->string(32)->notNull()->defaultValue('pending'),
            'lastError' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->null(),
            'type' => $this->string(32)->notNull(),
            'method' => $this->string(8)->null(),
            'endpoint' => $this->string(255)->null(),
            'statusCode' => $this->integer()->null(),
            'success' => $this->boolean()->notNull()->defaultValue(true),
            'durationMs' => $this->integer()->null(),
            'message' => $this->text()->null(),
            'requestBody' => $this->longText()->null(),
            'responseBody' => $this->longText()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, Table::INVOICES, ['orderId'], true);
        $this->createIndex(null, Table::INVOICES, ['sevdeskId'], false);
        $this->createIndex(null, Table::INVOICES, ['state'], false);
        $this->createIndex(null, Table::CONTACTS, ['customerKey'], true);
        $this->createIndex(null, Table::CONTACTS, ['sevdeskId'], false);
        $this->createIndex(null, Table::CREDITS, ['orderId', 'refundKey'], true);
        $this->createIndex(null, Table::LOG, ['orderId'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::INVOICES, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CREDITS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::CONTACTS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }
}
