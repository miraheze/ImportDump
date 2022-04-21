CREATE TABLE /*_*/importdump_requests (
  request_id INT AUTO_INCREMENT NOT NULL,
  request_actor BIGINT UNSIGNED NOT NULL,
  request_timestamp VARCHAR(32) NOT NULL,
  request_source TEXT NOT NULL,
  request_target VARCHAR(64) NOT NULL,
  request_file VARCHAR(500) DEFAULT NULL,
  request_reason BLOB NOT NULL,
  request_status VARCHAR(16) NOT NULL,
  PRIMARY KEY(request_id)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/request_id ON /*_*/importdump_requests (request_id);
