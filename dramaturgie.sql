PRAGMA encoding = 'UTF-8';
PRAGMA page_size = 8192;  -- blob optimisation https://www.sqlite.org/intern-v-extern-blob.html
PRAGMA foreign_keys = ON;
-- The VACUUM command may change the ROWIDs of entries in any tables that do not have an explicit INTEGER PRIMARY KEY
CREATE TABLE play (
  -- une pièce
  id      INTEGER, -- rowid auto
  code    TEXT,    -- nom de fichier sans extension, unique pour la base
  author  TEXT,    -- auteur
  title   TEXT,    -- titre
  year    INTEGER, -- année, reprise du nom de fichier, ou dans le XML
  acts    INTEGER, -- nombre d’actes, essentiellement 5, 3, 1 ; ajuster pour les prologues
  scenes  INTEGER, -- nombre de scènes
  verse   BOOLEAN, -- uniquement si majoritairement en vers, ne pas cocher si chanson mêlée à de la prose
  genre   TEXT,    -- comedy|tragedy
  c       INTEGER, -- <c> (char) taille en caractères
  w       INTEGER, -- <w> (word) taille en mots
  l       INTEGER, -- <l> taille en vers
  sp      INTEGER, -- <sp> taille en répliques
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX play_code ON play(code);

CREATE TABLE object (
  -- stockage de blobs pour les pièces, par exemple texte complet, tables des matières
  id       INTEGER, -- rowid auto
  play     INTEGER REFERENCES play(id),   -- la pièce à laquelle est attachée l’objet
  playcode INTEGER REFERENCES play(code), -- code de la pièce (raccourci)
  type     TEXT,    -- type d’objet
  code     TEXT,    -- ocde, au moins unique pour une pièce
  cont     BLOB,    -- contenu de l’objet
  PRIMARY  KEY(id ASC)
);
CREATE INDEX object_playcode ON object(playcode, type, code);

CREATE TABLE act (
  -- un acte
  id      INTEGER, -- rowid auto
  play    INTEGER REFERENCES play(id), -- id pièce
  code    TEXT,    -- code acte, unique pour la pièce
  n       INTEGER, -- numéro d’ordre dans la pièce
  label   TEXT,    -- intitulé affichabe
  type    TEXT,    -- type d’acte (prologue, interlude…)
  cn      INTEGER, -- numéro du premier caractère
  wn      INTEGER, -- numéro du premier mot
  ln      INTEGER, -- numéro du premier vers
  spn     INTEGER, -- numéro de répliques
  c       INTEGER, -- <c> (char) taille en caractères
  w       INTEGER, -- <w> (word) taille en mots
  l       INTEGER, -- <l> taille en vers
  sp      INTEGER, -- <sp> taille en répliques
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX act_code ON act(play, code);
CREATE INDEX act_type ON act(type);



CREATE TABLE scene (
  -- une scène (référence pour la présence d’un rôle)
  id      INTEGER, -- rowid auto
  play    INTEGER REFERENCES play(id),    -- id pièce
  act     INTEGER REFERENCES act(id),    -- id acte
  code    TEXT,    -- code scene, unique pour la pièce
  n       INTEGER, -- numéro d’ordre dans l’acte
  label   TEXT,    -- intitulé affichabe
  type    TEXT,    -- type de scene (prologue, interlude…)
  cn      INTEGER, -- numéro du premier caractère
  wn      INTEGER, -- numéro du premier mot
  ln      INTEGER, -- numéro du premier vers
  spn     INTEGER, -- numéro de répliques
  c       INTEGER, -- <c> (char) taille en caractères
  w       INTEGER, -- <w> (word) taille en mots
  l       INTEGER, -- <l> taille en vers
  sp      INTEGER, -- <sp> taille en répliques
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX scene_code ON scene(play, code);
CREATE INDEX scene_act ON scene(play, act);

CREATE TABLE configuration (
  -- une configuration est un état de la scène (personnages présents)
  id      INTEGER, -- rowid auto
  play    INTEGER REFERENCES play(id), -- code pièce
  code    TEXT,    -- code de conf (= @xml:id)
  n       INTEGER, -- numéro d’ordre dans la pièce
  label   TEXT,    -- liste de codes de personnage
  cn      INTEGER, -- numéro du premier caractère
  wn      INTEGER, -- numéro du premier mot
  ln      INTEGER, -- numéro du premier vers
  spn     INTEGER, -- numéro de répliques
  c       INTEGER, -- <c> (char) taille en caractères
  w       INTEGER, -- <w> (word) taille en mots
  l       INTEGER, -- <l> taille en vers
  sp      INTEGER, -- <sp> taille en répliques
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX configuration_code ON configuration(play, code);

CREATE TABLE stage (
  -- une didascalie
  id      INTEGER,  -- rowid auto
  play    INTEGER REFERENCES play(id), -- code pièce
  code    TEXT,    -- code de conf (= @xml:id)
  n       INTEGER, -- numéro d’ordre dans la pièce
  cn      INTEGER, -- numéro de caractère dans les répliques
  wn      INTEGER, -- numéro de mots dans les répliques
  ln      INTEGER, -- numéro de vers courant
  c       INTEGER, -- nombre de caractères dans la didascalie
  w       INTEGER, -- nombre de mots dans la didascalie
  text    TEXT,    -- texte, pour récup ultérieure ?
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX stage_code ON stage(play, code);


CREATE TABLE role (
  -- un rôle
  id       INTEGER,  -- rowid auto
  play     INTEGER REFERENCES play(id), -- code pièce
  code     TEXT,     -- code personne
  label    TEXT,     -- nom affichable
  title    TEXT,     -- description du rôle (mère de…, amant de…) tel que dans la source
  note     TEXT,     -- possibilité de description plus étendue
  rend     TEXT,     -- série de mots clés séparés d’espaces (male|female)? (cadet)
  sex      INTEGER,  -- 1: homme, 2: femme, null: ?, 0: asexué, 9: dieu, ISO 5218:2004
  age      TEXT,     -- (cadet|junior|senior|veteran)
  status   TEXT,     -- pour isoler les confidents, serviteurs, ou pédants
  targets  INTEGER,  -- nombre d’interlocuteurs
  oc        INTEGER,  -- out <c>, mombre de caractères dits
  ow        INTEGER,  -- out <w>, mombre de mots dits
  ol        INTEGER,  -- out <l>, nombre de vers dits
  osp       INTEGER,  -- out <sp>, nombre de répliques dites
  ic        INTEGER,  -- in <c>, mombre de caractères entendus
  iw        INTEGER,  -- in <w>, mombre de mots entendus
  il        INTEGER,  -- in <l>, nombre de vers entendus
  isp       INTEGER,  -- in <sp>, nombre de répliques entendues
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX role_who ON role(play, code);

CREATE TABLE sp (
  -- une réplique 
  id INTEGER,           -- rowid auto
  play          INTEGER REFERENCES play(id),    -- id pièce dans la base
  act           INTEGER REFERENCES act(id),     -- identifiant d’acte
  scene         INTEGER REFERENCES scene(id),   -- id de scene
  configuration INTEGER REFERENCES configuration(id),   -- id de configuration
  role          INTEGER REFERENCES role(id),   -- personnage qui parle
  code          TEXT,    -- identifiant de réplique dans le fichier
  cn            INTEGER, -- numéro du premier caractère
  wn            INTEGER, -- numéro du premier mot
  ln            INTEGER, -- numéro du premier vers
  c             INTEGER, -- <c> nombre de caractères
  w             INTEGER, -- <w> nombre de mots
  l             INTEGER, -- <l> nombre de vers
  text          TEXT,    -- texte, pour récup ultérieure ?
  PRIMARY KEY(id ASC)
);
CREATE UNIQUE INDEX sp_path ON sp(play, act, scene, code);
CREATE UNIQUE INDEX sp_cn ON sp(play, cn);
CREATE UNIQUE INDEX sp_wn ON sp(play, wn);
CREATE INDEX sp_ln ON sp(play, ln);

CREATE TABLE edge (
  -- destinataires d’une réplique
  id INTEGER,           -- rowid auto
  play INTEGER REFERENCES play(id), -- id pièce dans la base
  source INTEGER REFERENCES role(id), -- id de role = source
  target INTEGER REFERENCES role(id), -- id de role = target
  sp   INTEGER REFERENCES sp(id),   -- id de réplique = source
  PRIMARY KEY(id ASC)
);
CREATE INDEX edge_play ON edge(play);
CREATE INDEX edge_sp ON edge(sp);
CREATE INDEX edge_source ON edge(source, target);
CREATE INDEX edge_target ON edge(target, source);


CREATE TRIGGER playDel
  -- si on supprime une pièce, supprimer la cascade qui en dépend
  BEFORE DELETE ON play
  FOR EACH ROW BEGIN
    DELETE FROM object WHERE object.play = OLD.id;
    DELETE FROM act WHERE act.play = OLD.id;
    DELETE FROM scene WHERE scene.play = OLD.id;
    DELETE FROM configuration WHERE configuration.play = OLD.id;
    DELETE FROM role WHERE role.play = OLD.id;
    DELETE FROM sp WHERE sp.play = OLD.id;
    DELETE FROM edge WHERE edge.play = OLD.id;
END;
