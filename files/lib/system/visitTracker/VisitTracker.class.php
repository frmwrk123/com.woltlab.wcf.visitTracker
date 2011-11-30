<?php
namespace wcf\system\visitTracker;
use wcf\data\object\type\ObjectTypeCache;
use wcf\system\exception\SystemException;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\SingletonFactory;
use wcf\system\WCF;

class VisitTracker extends SingletonFactory {
	/**
	 * default tracking lifetime
	 * @var integer
	 */
	const DEFAULT_LIFETIME = 604800; // = one week
	
	/**
	 * list of available object types
	 * @var array
	 */
	protected $availableObjectTypes = array();
	
	/**
	 * user visits
	 * @var array
	 */
	protected $userVisits = array();
	
	/**
	 * @see wcf\system\SingletonFactory::init()
	 */
	protected function init() {
		// get available object types
		$this->availableObjectTypes = ObjectTypeCache::getInstance()->getObjectTypes('com.woltlab.wcf.visitTracker.objectType');
	}
	
	/**
	 * Gets the object type id.
	 * 
	 * @param	string 		$objectType
	 * @return	integer
	 */
	public function getObjectTypeID($objectType) {
		if (!isset($this->availableObjectTypes[$objectType])) {
			throw new SystemException("unknown object type '".$objectType."'");
		}
		
		return $this->availableObjectTypes[$objectType]->objectTypeID;
	}
	
	/**
	 * Gets the last visit time for a whole object type.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$userID
	 * @return	integer
	 */
	public function getVisitTime($objectType, $userID) {
		$objectTypeID = $this->getObjectTypeID($objectType);
		if (!isset($this->userVisits[$userID])) {
			$this->userVisits[$userID] = array();
		
			if ($userID) {
				// get data from storage
				UserStorageHandler::getInstance()->loadStorage(array($userID));
						
				// get ids
				$data = UserStorageHandler::getInstance()->getStorage(array($userID), 'trackedUserVisits');
					
				// cache does not exist or is outdated
				if ($data[$userID] === null) {
					$sql = "SELECT 	objectTypeID, visitTime
						FROM 	wcf".WCF_N."_tracked_visit_type
						WHERE	userID = ?";
					$statement = WCF::getDB()->prepareStatement($sql);
					$statement->execute(array($userID));
					while ($row = $statement->fetchArray()) {
						$this->userVisits[$userID][$row['objectTypeID']] = $row['visitTime'];
					}
					
					// update storage data
					UserStorageHandler::getInstance()->update($userID, 'trackedUserVisits', serialize($this->userVisits[$userID]));
				}
				else {
					$this->userVisits[$userID] = @unserialize($data[$userID]);
					if (!$this->userVisits[$userID]) {
						$this->userVisits[$userID] = array();
					}
				}
			}
		}
		
		if (isset($this->userVisits[$userID][$objectTypeID])) {
			return $this->userVisits[$userID][$objectTypeID];
		}
		
		if ($this->availableObjectTypes[$objectType]->lifetime) {
			return TIME_NOW - $this->availableObjectTypes[$objectType]->lifetime;
		}
		
		return TIME_NOW - self::DEFAULT_LIFETIME;
	}
	
	/**
	 * Gets the last visit time for a specific object.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$objectID
	 * @param	integer		$userID
	 * @return	integer
	 */
	public function getObjectVisitTime($objectType, $objectID, $userID) {
		$sql = "SELECT	visitTime
			FROM	wcf".WCF_N."_tracked_visit
			WHERE	objectTypeID = ?
				AND objectID = ?
				AND userID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $objectID, $userID));
		$row = $statement->fetchArray();
		if ($row) return $row['visitTime'];
		
		return $this->getVisitTime($objectType, $userID);
	}
	
	/**
	 * Deletes all tracked visits of a specific object type.
	 * 
	 * @param 	string		$objectType
	 * @param	integer		$userID
	 */
	public function deleteObjectVisits($objectType, $userID) {
		$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
			WHERE		objectTypeID = ?
					AND userID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $userID));
	}
	
	/**
	 * Tracks an object visit.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$objectID
	 * @param	integer		$userID
	 * @param	integer		$time
	 */
	public function trackObjectVisit($objectType, $objectID, $userID, $time = TIME_NOW) {
		// delete old visit
		$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
			WHERE		objectTypeID = ?
					AND objectID = ?
					AND userID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $objectID, $userID));
		
		// save visit
		$sql = "INSERT INTO	wcf".WCF_N."_tracked_visit
					(objectTypeID, objectID, userID, visitTime)
			VALUES		(?, ?, ?, ?)";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $objectID, $userID, $time));
	}
	
	/**
	 * Tracks an object type visit.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$userID
	 * @param	integer		$time
	 */
	public function trackTypeVisit($objectType, $userID, $time = TIME_NOW) {
		// delete old visit
		$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit_type
			WHERE		objectTypeID = ?
					AND userID = ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $userID));
		
		// save visit
		$sql = "INSERT INTO	wcf".WCF_N."_tracked_visit_type
					(objectTypeID, userID, visitTime)
			VALUES		(?, ?, ?)";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $userID, $time));
		
		// delete obsolete object visits
		$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
			WHERE		objectTypeID = ?
					AND userID = ?
					AND visitTime <= ?";
		$statement = WCF::getDB()->prepareStatement($sql);
		$statement->execute(array($this->getObjectTypeID($objectType), $userID, $time));
		
		// reset storage
		UserStorageHandler::getInstance()->reset(array($userID), 'trackedUserVisits');
	}
}
