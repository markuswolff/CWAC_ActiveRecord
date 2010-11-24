CREATE TABLE publisher (
  id       integer auto_increment,
  `name`   varchar(120),
  PRIMARY KEY(id)
);

CREATE TABLE book (
  id            integer auto_increment,
  title         varchar(120),
  author        varchar(80),
  publisher_id  integer,
  PRIMARY KEY(id)
);

