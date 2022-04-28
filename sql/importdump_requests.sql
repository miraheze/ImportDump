CREATE TABLE /*_*/importdump_requests (
  request_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  request_actor BIGINT UNSIGNED NOT NULL,
  request_timestamp BINARY(14) NOT NULL,
  request_source TEXT NOT NULL,
  request_target VARCHAR(64) NOT NULL,
  request_reason BLOB NOT NULL,
  request_status ENUM( 'complete', 'declined', 'inprogress', 'pending' ) NOT NULL,
  request_locked TINYINT UNSIGNED DEFAULT 0 NOT NULL,
  request_private TINYINT UNSIGNED DEFAULT 0 NOT NULL,
  INDEX request_actor_timestamp (request_actor, request_timestamp),
  INDEX request_timestamp (request_timestamp),
  INDEX request_target (request_target),
  INDEX request_status (request_status),
  PRIMARY KEY(request_id)
) /*$wgDBTableOptions*/;
