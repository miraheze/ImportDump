CREATE TABLE /*_*/importdump_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY NOT NULL,
  request_source VARCHAR(96) DEFAULT NULL,
  request_target VARCHAR(96) NOT NULL,
  request_file VARCHAR(500) DEFAULT NULL,
  request_reason TEXT DEFAULT NULL,
  request_status VARCHAR(16) DEFAULT NULL,
  request_user INT(10) NOT NULL
) /*$wgDBTableOptions*/;
