CREATE TABLE /*_*/importdump_request_comments (
  request_id INT NOT NULL,
  request_comment_actor BIGINT UNSIGNED NOT NULL,
  request_comment_text BLOB NOT NULL,
  request_comment_timestamp VARCHAR(32) NOT NULL
) /*$wgDBTableOptions*/;
