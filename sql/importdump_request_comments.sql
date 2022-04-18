CREATE TABLE /*_*/importdump_request_comments (
  request_id INT NOT NULL,
  request_comment BLOB NOT NULL,
  request_comment_timestamp VARCHAR(32) NOT NULL,
  request_comment_actor INT(10) NOT NULL
) /*$wgDBTableOptions*/;
