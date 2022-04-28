CREATE TABLE /*_*/importdump_request_comments (
  request_id BIGINT UNSIGNED NOT NULL,
  request_comment_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  request_comment_actor BIGINT UNSIGNED NOT NULL,
  request_comment_text BLOB NOT NULL,
  request_comment_timestamp BINARY(14) NOT NULL,
  INDEX request_id (request_id),
  INDEX request_comment_timestamp (request_comment_timestamp),
  PRIMARY KEY(request_comment_id)
) /*$wgDBTableOptions*/;
