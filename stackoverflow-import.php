<?php
/**
 * ------------------------------------------------------------------
 * Read XML files from the StackExchange data dump and import it
 * into a MySQL database.
 *
 * http://www.clearbits.net/creators/146-stack-exchange-data-dump
 *
 * Usage:
 *  To be written.
 *
 * Copyright 2012-2013 Bill Karwin.
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ------------------------------------------------------------
 */

abstract class XmlImport
{
  protected $dsn;
  protected $user;
  protected $password;
  protected $pdo;
  protected $attr = array();
  protected $defaultParams = array();
  protected $lookup = array();
  protected $lookupMap = array();

  protected abstract function initAttr();

  public function __construct($dsn, $user, $password) {
    $this->dsn = $dsn;
    $this->user = $user;
    $this->password = $password;
    $this->initAttr();
  }

  protected function prepareLookupInsert() {
    $table = current($this->lookup)."s";
    $sql = "INSERT INTO $table (Description) VALUES (:Description)";
    $this->lookupStmt = $this->pdo->prepare($sql);
  }

  protected function lookup($xml) {
    $name = key($this->lookup);
    $value = (string) $xml->getAttribute($name);
    if (!array_key_exists($value, $this->lookupMap)) {
      $params = array("Description" => $value);
      if ($this->lookupStmt->execute($params) === false ) {
	print "Line " . __LINE__ . ": ";
	print_r($params);
	$e = $this->lookupStmt->errorInfo();
	print $e[2] . "\n";
	return false;
      }
      $seq = strtolower(current($this->lookup) . "s_"
        . current($this->lookup) . "id_seq");
      $this->lookupMap[$value] = $this->pdo->lastInsertId($seq);
    }
    return $this->lookupMap[$value];
  }

  protected function prepareInsert() {
    $table = get_class($this);

    $sql = "INSERT INTO $table ("
      . join(",", array_values($this->attr))
      . ") VALUES ("
      . join(",", array_map(
	  function ($str) { return ":".$str; },
	  array_values($this->attr)
	)
      )
      . ")";
    $this->stmt = $this->pdo->prepare($sql);

  }

  public function load($file) {
    echo "Loading data for " . get_class($this) . "...\n";

    $xml = new XMLReader();
    $xml->open($file);

    $this->pdo = new PDO($this->dsn, $this->user, $this->password);
    $this->pdo->beginTransaction();
    $this->prepareInsert();
    if ($this->lookup) {
      $lookupKey = current($this->lookup) . "Id";
      $this->prepareLookupInsert();
    }

    $i = 0;
    $j = 0;
    while ($xml->read()) {
      if ($xml->nodeType != XMLREADER::ELEMENT || $xml->localName != "row") {
	continue;
      }

      $params = array_combine(
	array_values($this->attr),
	array_fill(0, count($this->attr), null)
      );
      $params = array_merge($params, $this->defaultParams);
      if ($this->lookup) {
        $params[$lookupKey] = $this->lookup($xml);
      }

      foreach ($this->attr as $k=>$v) {
	if ($xml->getAttribute($k)) {
	  $params[$v] = $xml->getAttribute($k);
	}
      }

      if ($this->stmt->execute($params) === false) {
	print "Line " . __LINE__ . "($i): ";
	print_r($params);
	$e = $this->stmt->errorInfo();
	print $e[2] . "\n";
      }

      $i++;
      if ($i % 10000 == 0) {
	$this->pdo->commit();
	echo ".";
	$j++;
	if ($j % 80 == 0) {
	  $this->pdo = null;
	  $this->pdo = new PDO($this->dsn, $this->user, $this->password);
	  echo "\n";
	}
	$this->pdo->beginTransaction();
      }
    }
    $this->pdo->commit();
    echo " total: $i\n";
  }
}

class Users extends XmlImport
{
  protected $defaultParams = array(
      "Reputation"          => "1",
      "DisplayName"         => "",
      "Views"               => "0",
      "UpVotes"             => "0",
      "DownVotes"           => "0"
  );
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "UserId",
      "Reputation"          => "Reputation",
      "CreationDate"        => "CreationDate",
      "DisplayName"         => "DisplayName",
      "LastAccessDate"      => "LastAccessDate",
      "WebsiteUrl"          => "WebsiteUrl",
      "Location"            => "Location",
      "Age"                 => "Age",
      "AboutMe"             => "AboutMe",
      "Views"               => "Views",
      "UpVotes"             => "UpVotes",
      "DownVotes"           => "DownVotes"
    );
  }
}

class Badges extends XmlImport
{
  protected $lookup = array(
      "Name"                => "BadgeType"
  );
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "BadgeId",
      "BadgeTypeId"         => "BadgeTypeId",
      "UserId"              => "UserId",
      "Date"                => "CreationDate"
    );
  }
}

class Posts extends XmlImport
{
  protected $defaultParams = array(
    "Score"                 => "0",
    "ViewCount"             => "0",
    "Body"                  => "",
    "OwnerDisplayName"      => "",
    "Title"                 => "",
    "Tags"                  => "",
    "AnswerCount"           => "0",
    "CommentCount"          => "0",
    "FavoriteCount"         => "0"
  );
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "PostId",
      "PostTypeId"          => "PostTypeId",
      "AcceptedAnswerId"    => "AcceptedAnswerId",
      "ParentId"            => "ParentId",
      "CreationDate"        => "CreationDate",
      "Score"               => "Score",
      "ViewCount"           => "ViewCount",
      "Body"                => "Body",
      "OwnerUserId"         => "OwnerUserId",
      "OwnerDisplayName"    => "OwnerDisplayName",
      "LastEditorUserId"    => "LastEditorUserId",
      "LastEditDate"        => "LastEditDate",
      "LastActivityDate"    => "LastActivityDate",
      "Title"               => "Title",
      "Tags"                => "Tags",
      "AnswerCount"         => "AnswerCount",
      "CommentCount"        => "CommentCount",
      "FavoriteCount"       => "FavoriteCount",
      "ClosedDate"          => "ClosedDate"
    );
  }
}

class Posthistory extends XmlImport
{
  protected $defaultParams = array(
    "UserDisplayName"       => "N/A"
  );
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "PostHistoryId",
      "PostHistoryTypeId"   => "PostHistoryTypeId",
      "PostId"              => "PostId",
      "RevisionGUID"        => "RevisionGUID",
      "CreationDate"        => "CreationDate",
      "UserId"              => "UserId",
      "UserDisplayName"     => "UserDisplayName",
      "Comment"             => "Comment",
      "Text"                => "Text",
      "CloseReasonId"       => "CloseReasonId"
    );
  }
}

class Comments extends XmlImport
{
  protected $defaultParams = array(
      "UserId"              => "-1"
  );
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "CommentId",
      "PostId"              => "PostId",
      "UserId"              => "UserId",
      "Text"                => "Text",
      "CreationDate"        => "CreationDate"
    );
  }
}

class Votes extends XmlImport
{
  protected function initAttr() {
    $this->attr = array(
      "Id"                  => "VoteId",
      "PostId"              => "PostId",
      "VoteTypeId"          => "VoteTypeId",
      "CreationDate"        => "CreationDate",
      "UserId"              => "UserId"
    );
  }
}

$options_def = array(
  "u:"=>"user:",
  "p:"=>"password:",
  "h:"=>"host:",
  "d:"=>"database:",
  "f:"=>"file:",
  "v"=>"verbose",
);
$options = array(
  "u"=>"root",
  "p"=>"root",
  "h"=>"localhost",
  "d"=>"testpattern",
);
$options = array_merge($options, getopt(implode("", array_keys($options_def)), array_values($options_def)));

$dsn = "mysql:host={$options['h']};dbname=${options['d']}";

if (!isset($options["f"]) || !is_file($options["f"])) {
  echo "Can't find file {$options['f']}. Exiting.\n";
  exit(1);
}

$b = basename($options["f"], ".xml");
$classname = ucfirst($b);

if (!class_exists($classname)) {
  echo "Unknown class '$classname'. Exiting.\n";
  exit(1);
}

$loader = new $classname($dsn, $options["u"], $options["p"]);
$loader->load($options["f"]);

exit(0);
