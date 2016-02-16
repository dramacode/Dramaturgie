<?php
// declare(encoding = 'utf-8');
setlocale(LC_ALL, 'fr_FR.utf8');
mb_internal_encoding("UTF-8");
foreach (array('Charline.php', 'Rolenet.php', 'Doc.php') as $file) include(dirname(__FILE__).'/'.$file);
include('../Teinte/Doc.php'); // dépendance déclarée

if (realpath($_SERVER['SCRIPT_FILENAME']) != realpath(__FILE__)); // file is include do nothing
else if (php_sapi_name() == "cli") {
  Dramaturgie_Base::cli();
}
class Dramaturgie_Base {
  /** Lien à une base SQLite, unique */
  public $pdo;


  /**
   * Connexion à une base sqlite
   */
  public function __construct($sqlitefile=null) {
    if ($sqlitefile) $this->connect($sqlitefile);
  }
  /**
   * Connexion à la base
   */
  private function connect($sqlite) {
    $sql = 'dramaturgie.sql';
    $dsn = "sqlite:" . $sqlite;
    // si la base n’existe pas, la créer
    if (!file_exists($sqlite)) {
      if (!file_exists($dir = dirname($sqlite))) {
        mkdir($dir, 0775, true);
        @chmod($dir, 0775);  // let @, if www-data is not owner but allowed to write
      }
      $this->pdo = new PDO($dsn);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
      @chmod($sqlite, 0775);
      $this->pdo->exec(file_get_contents(dirname(__FILE__).'/'.$sql));
    }
    else {
      $this->pdo = new PDO($dsn);
      $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    // table temporaire en mémoire
    $this->pdo->exec("PRAGMA temp_store = 2;");
  }
  /**
   * Ligne bibliographique pour une pièce
   */
  public function bibl($play) {
    if (is_string($play)) {
      $playcode = $this->pdo->quote($playcode);
      $play = $this->pdo->query("SELECT * FROM play WHERE code = $playcode")->fetch();
    }
    $bibl = $play['author'].', '.$play['title'];
    $meta = array();
    if ($play['year']) $meta[] = $play['year'];
    if ($play['genre'] == 'tragedy') $meta[] = 'tragédie';
    else if ($play['genre'] == 'comedy') $meta[] = 'comédie';
    if ($play['acts']) $meta[] = $play['acts'].(($play['acts']>2)?" actes":" acte");
    $meta[] = (($play['verse'])?"vers":"prose");
    if (count($meta)) $bibl .= " (".implode(", ", $meta).")";
    return $bibl;
  }


  /**
   * Lister les fichiers XML d’un répertoire pour en proposer une table avec quelques métadonnées
   */
  public function dirtable($dir) {
    $dir = rtrim($dir, ' /\\').'/';
    foreach(glob($dir.'*.xml') as $file) {
      $play = $this->play($file);
      echo '
  <tr>
    <td class="author">'.$play['author'].'</td>
    <td class="year">'.$play['year'].'</td>
    <td class="title"><a href="'.basename($file).'">'.$play['title'].'</a></td>
    <td class="genre">'.$play['genre'].'</td>
    <td class="verse">'.(($play['verse'])?"vers":"prose").'</td>
    <td class="acts">'.$play['acts'].'</td>
  </tr>
';
    }
  }
  /**
   * Charger un XML en base
   */
  public function insert($file) {
    $time = microtime(true);
    if (STDERR) fwrite(STDERR, $file);
    $doc = new Dramaturgie_Doc($file);
    $play = $doc->meta();
    $this->pdo->exec("DELETE FROM play WHERE code = ".$this->pdo->quote($play['code']));
    $q = $this->pdo->prepare("
    INSERT INTO play (code, author, title, year, acts, verse, genre)
              VALUES (?,    ?,      ?,     ?,    ?,    ?,     ?);
    ");
    $q->execute(array(
      $play['code'],
      $play['author'],
      $play['title'],
      $play['year'],
      $play['acts'],
      $play['verse'],
      $play['genre'],
    ));
    $playid = $this->pdo->lastInsertId();
    // roles
    $cast = $doc->cast();
    $this->pdo->beginTransaction();
    $q = $this->pdo->prepare("
    INSERT INTO role (play, code, label, title, note, rend, sex, age, status)
              VALUES (?,    ?,    ?,     ?,     ?,    ?,    ?,   ?,   ?);
    ");
    foreach ($cast as $role) {
      $q->execute(array(
        $playid,
        $role['code'],
        $role['label'],
        $role['title'],
        $role['note'],
        $role['rend'],
        $role['sex'],
        $role['age'],
        $role['status'],
      ));
      // conserver le rowid du role
      $cast[$role['code']]['id'] = $this->pdo->lastInsertId();;
    }
    $this->pdo->commit();

    if (STDERR) fwrite(STDERR, " meta+role: ".number_format(microtime(true) - $time, 3)."s. ");
    $time = microtime(true);


    $csv = $doc->csv();
    // placer la chaîne dans un stream pour profiter du parseur fgetscsv
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $inact = $this->pdo->prepare("
    INSERT INTO act (play, code, n, label, type, cn, wn, ln)
            VALUES  (?,    ?,    ?, ?,     ?,    ?,  ?,  ?);
    ");
    $inscene = $this->pdo->prepare("
    INSERT INTO scene (play, act, code, n, label, type, cn, wn, ln)
               VALUES (?,    ?,   ?,    ?, ?,     ?,    ?,  ?,  ?);
    ");
    $inconf = $this->pdo->prepare("
    INSERT INTO configuration (play, act, scene, code, n, label, cn, wn, ln)
                       VALUES (?,    ?,   ?,     ?,   ?,  ?,     ?,  ?,  ?);
    ");
    $inpresence = $this->pdo->prepare("
    INSERT INTO presence (play, configuration, role)
                  VALUES (?,    ?,             ?);
    ");
    $insp = $this->pdo->prepare("
    INSERT INTO sp (play, act, scene, configuration, role, code, cn, wn, ln, c, w, l, text)
            VALUES (?,    ?,   ?,     ?,             ?,    ?,    ?,  ?,  ?,  ?, ?, ?, ?);
    ");
    $instage = $this->pdo->prepare("
    INSERT INTO stage (play, act, scene, configuration, code, n, cn, wn, ln, c, w, text)
               VALUES (?,    ?,   ?,     ?,             ?,    ?, ?,  ?,  ?,  ?, ?, ?);
    ");
    $intarget = $this->pdo->prepare("
    INSERT INTO edge (play, sp, source, target)
              VALUES (?,    ?,  ?,      ?);
    ");
    // première ligne, nom de colonne, à utiliser comme clé pour la collecte des lignes
    $keys = fgetcsv($stream, 0, "\t");
    // boucle pour charger la base, ne pas oublier de démarrer une transaction
    $this->pdo->beginTransaction();
    $wn = 1;
    $cn = 1;
    $ln = null;
    $actid = null;
    $sceneid = null;
    $conf = array();
    $confid = null; // peut ne pas commencer tout de suite
    while (($values = fgetcsv($stream, 0, "\t")) !== FALSE) {
      // fabriquer un tableau clé valeur avec la ligne csv
      if (count($keys) > count($values)) { // moins de valeurs que de clés, il faut combler
        $data = array_combine($keys, array_merge($values, array_fill(0, count($keys) - count($values), null)));
      }
      else {
        $data = array_combine($keys, $values);
      }
      // configuration
      if ($data['object'] == 'configuration' ) {
        $confid = null;
        $data['label'] = trim($data['label']);
        try {
            $inconf->execute(array(
            $playid,
            $actid,
            $sceneid,
            $data['code'],
            $data['n'],
            $data['label'],
            $cn,
            $wn,
            $ln,
          ));
          $confid = $this->pdo->lastInsertId();
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE scene ? ".$data['code']."\n".$e."\n\n");
        }
        // record the availabe roles for this configuration
        if ($data['label']) {
          // space separated who codes
          $conf = array_flip(explode(' ', $data['label']));
          // test if unknow role, if known add it as a presence
          foreach ($conf as $k=>$v) {
            if (STDERR && !isset($cast[$k])) {
              fwrite(STDERR, 'person/@corresp="'.$k. '" unknown role ['.$data['code']."]\n");
            }
            else {
              $inpresence->execute(array(
                $playid,
                $confid,
                $cast[$k]['id'],
              ));
            }
          }
        }
        else $conf = array();
      }
      // réplique
      else if ($data['object'] == 'sp' ) {
        if (!isset($cast[$data['label']]) && STDERR) fwrite(STDERR, "sp/@who not in castlist ".$data['label']. " [".$data['code']."]\n");
        if (!isset($conf[$data['label']]) && STDERR) fwrite(STDERR, 'sp/@who="'.$data['label'].'" not in configuration $confid ("'.implode('", "', array_keys($conf)).'")  ['.$data['code']."]\n");
        if (!$data['c']) {
          if (STDERR) fwrite(STDERR, "Empty <sp> [".$data['code']."]\n");
          continue;
        }
        if (!$data['l']) $data['l'] = null;
        if ($data['ln']) $ln = $data['ln'];
        $sourceid = $cast[$data['label']]['id'];
        try {
          $row = array(
            $playid,
            $actid,
            $sceneid,
            $confid,
            $sourceid,
            $data['code'],
            $cn,
            $wn,
            $ln,
            $data['c'],
            $data['w'],
            $data['l'],
            $data['text'], // text
          );
          $insp->execute($row);
          // increment after insert
          $wn = $wn + $data['w'];
          $cn = $cn + $data['c'];
          $spid = $this->pdo->lastInsertId();
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      <sp>".implode(', ', $row)."\n".$data['code']."\n".$e."\n\n");
        }
        $target = '';
        try {
          // ajouter les destinataires de la réplique, dépend de la conf
          if (!count($conf)); // erreur ?
          // monologue
          else if (1 == count($conf)) {
            $intarget->execute(array(
              $playid,
              $spid,
              $sourceid,
              $sourceid,
            ));
          }
          //
          else {
            // destinataire principal de la réplique
            if ($data['target']) {
              $targetid = $cast[$data['target']]['id'];
              $intarget->execute(array(
                $playid,
                $spid,
                $sourceid,
                $targetid,
              ));
              // ne doit pas arriver
              // if ($sourceid == $targetid) echo "\n––––– ".$data['label'].' : '.$data['text'].' ';
            }
            $i = 1;
            foreach ($conf as $k=>$null) {
              if (!isset($cast[$k])) continue; // error
              // ne se parle pas si plus d’une personne
              if ($cast[$k]['id'] == $sourceid) continue;
              // déjà fait
              if ($cast[$k]['id'] == $targetid) continue;
              $intarget->execute(array(
                $playid,
                $spid,
                $sourceid,
                $cast[$k]['id'],
              ));

              if (!--$i) break;
            }
          }
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      edge: ".$source.'  > '.$target.' '.$k."\n".$e."\n\n");
        }
      }
      // scènes, parfois non contenues dans un acte (pièces en un acte)
      else if ($data['object'] == 'div2' || $data['type'] == 'scene' ) {
        $sceneid = null;
        // ne pas annuler la configuration, peut courir entre les actes et les scènes
        if (!$data['type']) $data['type'] = null;
        try {
          $inscene->execute(array(
            $playid,
            $actid,
            $data['code'],
            $data['n'],
            $data['label'],
            $data['type'],
            $cn,
            $wn,
            $ln,
          ));
          $sceneid = $this->pdo->lastInsertId();
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE scene ? ".$data['code']."\n".$e."\n\n");
        }
      }
      // actes
      else if ($data['object'] == 'div1' ) {
        $sceneid = null;
        $actid = null;
        // ne pas annuler la configuration, peut courir entre les actes et les scènes
        if(!$data['type']) $data['type'] = 'act';
        try {
          $inact->execute(array(
            $playid,
            $data['code'],
            $data['n'],
            $data['label'],
            $data['type'],
            $cn,
            $wn,
            $ln,
          ));
          $actid = $this->pdo->lastInsertId();
        }
        catch (Exception $e) {
          if (STDERR) fwrite(STDERR, "\n\n      NOT UNIQUE act ? ".$data['code']."\n".$e."\n\n");
        }
      }
      // didascalies
      else if ($data['object'] == 'stage' ) {
        // (play, code, n, cn, wn, ln, c, w, text)
        $instage->execute(array(
          $playid,
          $actid,
          $sceneid,
          $confid,
          $data['code'],
          $data['n'],
          $cn,
          $wn,
          $ln,
          $data['c'],
          $data['w'],
          $data['text'],
        ));
      }

    }
    $this->pdo->commit();
    // différentes stats prédef
    if (STDERR) fwrite(STDERR, " sp: ".number_format(microtime(true) - $time, 3)."s. ");
    $time = microtime(true);
    $this->_sqlstats($playid);
    if (STDERR) fwrite(STDERR, " stats: ".number_format(microtime(true) - $time, 3)."s.");
    $time = microtime(true);

    $this->_insobj($file, $playid, $play['code']);
    if (STDERR) fwrite(STDERR, " html: ".number_format(microtime(true) - $time, 3)."s.");
    if (STDERR) fwrite(STDERR, "\n");
  }
  /**
   * Statistiques SQL précalculées
   */
  function _sqlstats($playid) {
    $this->pdo->beginTransaction();
    $this->pdo->exec("UPDATE play SET sp = (SELECT COUNT(*) FROM sp WHERE play = $playid) WHERE id = $playid;");
    $this->pdo->exec("UPDATE play SET l = (SELECT SUM(l) FROM sp WHERE play = $playid) WHERE id = $playid;");
    $this->pdo->exec("UPDATE play SET w = (SELECT SUM(w) FROM sp WHERE play = $playid) WHERE id = $playid;");
    $this->pdo->exec("UPDATE play SET c = (SELECT SUM(c) FROM sp WHERE play = $playid) WHERE id = $playid;");
    $this->pdo->exec("UPDATE play SET scenes = (SELECT COUNT(*) FROM scene WHERE play = $playid) WHERE id = $playid;");

    $this->pdo->exec("UPDATE act SET sp = (SELECT COUNT(*) FROM sp WHERE sp.act = act.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE act SET l = (SELECT SUM(l) FROM sp WHERE sp.act = act.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE act SET w = (SELECT SUM(w) FROM sp WHERE sp.act = act.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE act SET c = (SELECT SUM(c) FROM sp WHERE sp.act = act.id ) WHERE play = $playid;");
    $this->pdo->exec("UPDATE act SET confs = (SELECT COUNT(DISTINCT configuration) FROM sp WHERE sp.act = act.id) WHERE play = $playid;");

    $this->pdo->exec("UPDATE scene SET sp = (SELECT COUNT(*) FROM sp WHERE sp.scene = scene.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE scene SET c = (SELECT SUM(c) FROM sp WHERE sp.scene = scene.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE scene SET w = (SELECT SUM(w) FROM sp WHERE sp.scene = scene.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE scene SET l = (SELECT SUM(l) FROM sp WHERE sp.scene = scene.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE scene SET confs = (SELECT COUNT(DISTINCT configuration) FROM sp WHERE sp.scene = scene.id) WHERE play = $playid;");

    $this->pdo->exec("UPDATE configuration SET sp = (SELECT COUNT(*) FROM sp WHERE sp.configuration = configuration.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE configuration SET c = (SELECT SUM(c) FROM sp WHERE sp.configuration = configuration.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE configuration SET w = (SELECT SUM(w) FROM sp WHERE sp.configuration = configuration.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE configuration SET l = (SELECT SUM(l) FROM sp WHERE sp.configuration = configuration.id) WHERE play = $playid;");



    $this->pdo->exec("UPDATE role SET targets = (SELECT COUNT(DISTINCT target) FROM edge WHERE edge.source = role.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE role SET sources = (SELECT COUNT(DISTINCT source) FROM edge WHERE edge.target = role.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE role SET confs = (SELECT COUNT(DISTINCT configuration) FROM sp WHERE sp.role = role.id) WHERE play = $playid;");

    $this->pdo->exec("UPDATE role SET sp = (SELECT COUNT(*) FROM sp WHERE sp.role = role.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE role SET l = (SELECT SUM(sp.l) FROM sp WHERE sp.role = role.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE role SET w = (SELECT SUM(sp.w) FROM sp WHERE sp.role = role.id) WHERE play = $playid;");
    $this->pdo->exec("UPDATE role SET c = (SELECT SUM(sp.c) FROM sp WHERE sp.role = role.id) WHERE play = $playid;");

    $this->pdo->commit();
  }
  /**
   * Insérer de contenus, à ne pas appeller n’importe comment (demande à ce qu’un TEI soit chargé en DOM)
   */
  function _insobj($file, $playid, $playcode) {
    $insert = $this->pdo->prepare("
    INSERT INTO object (play, playcode, type, code, cont)
                VALUES (?,    ?,        ?,    ?,    ?)
    ");
    /*
    // insérer charline
    ob_start();
    $this->charline($playcode);
    $cont = ob_get_contents();
    ob_end_clean();
    $insert->execute(array(
      $playid,
      $playcode,
      'charline',
      null,
      $cont,
    ));
    // insérer json sigma
    ob_start();
    $this->sigma($playcode);
    $cont = ob_get_contents();
    ob_end_clean();
    $insert->execute(array(
      $playid,
      $playcode,
      'sigma',
      null,
      $cont,
    ));
    */
    // insérer des transformations du fichier
    $teinte = new Teinte_Doc($file);
    $insert->execute(array( $playid, $playcode, 'article', null, $teinte->article() ));
    $insert->execute(array( $playid, $playcode, 'toc', null, $teinte->toc('nav') ));
    $insert->execute(array( $playid, $playcode, 'tocfront', null, $teinte->toc('front') ));
    $insert->execute(array( $playid, $playcode, 'tocback', null, $teinte->toc('back') ));
  }

  /**
   * Command line API
   */
  static function cli() {
    $timeStart = microtime(true);
    $usage = '
    usage    : php -f '.basename(__FILE__).' base.sqlite {action} {arguments}
    where action can be
    valid  "../*.xml"
    insert "../*.xml"
    gephi playcode
';
    $timeStart = microtime(true);
    array_shift($_SERVER['argv']); // shift first arg, the script filepath
    if (!count($_SERVER['argv'])) exit($usage);
    $sqlite = array_shift($_SERVER['argv']);
    $base = new Dramaturgie_Base($sqlite);
    if (!count($_SERVER['argv'])) exit('
    action  ? (valid|insert|gephi)
');
    $action = array_shift($_SERVER['argv']);
    if ($action == 'insert') {
      if (!count($_SERVER['argv'])) exit('
    insert requires a file or a glob expression to insert XML/TEI play file
');
      foreach ($_SERVER['argv'] as $glob) {
        foreach(glob($glob) as $file) {
          // spécifique Molière
          if (preg_match('@-livret\.@', $file)) continue;
          $base->insert($file);
        }
      }
    }
    if ($action == 'gephi') {
      $base->gephi(array_shift($_SERVER['argv']));
    }
  }
}
?>
