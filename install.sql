DROP TABLE IF EXISTS wcf1_tracked_visit;
CREATE TABLE wcf1_tracked_visit (
	objectTypeID INT(10) NOT NULL,
	objectID INT(10) NOT NULL,
	userID INT(10) NOT NULL,
	visitTime INT(10) NOT NULL DEFAULT 0,
	UNIQUE KEY (objectTypeID, objectID, userID),
	KEY (userID, visitTime)
);

DROP TABLE IF EXISTS wcf1_tracked_visit_type;
CREATE TABLE wcf1_tracked_visit_type (
	objectTypeID INT(10) NOT NULL,
	userID INT(10) NOT NULL,
	visitTime INT(10) NOT NULL DEFAULT 0,
	UNIQUE KEY (objectTypeID, userID),
	KEY (userID, visitTime)
);

ALTER TABLE wcf1_tracked_visit ADD FOREIGN KEY (objectTypeID) REFERENCES wcf1_object_type (objectTypeID) ON DELETE CASCADE;
ALTER TABLE wcf1_tracked_visit ADD FOREIGN KEY (userID) REFERENCES wcf1_user (userID) ON DELETE CASCADE;

ALTER TABLE wcf1_tracked_visit_type ADD FOREIGN KEY (objectTypeID) REFERENCES wcf1_object_type (objectTypeID) ON DELETE CASCADE;
ALTER TABLE wcf1_tracked_visit_type ADD FOREIGN KEY (userID) REFERENCES wcf1_user (userID) ON DELETE CASCADE;
