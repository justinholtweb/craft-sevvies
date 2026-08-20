<?php

namespace justinholtweb\sevvies\records;

use craft\db\ActiveRecord;
use justinholtweb\sevvies\db\Table;

class ContactRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::CONTACTS;
    }
}
