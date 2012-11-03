<?php
namespace wcf\system\visitTracker;
use wcf\data\object\type\ObjectTypeCache;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\exception\SystemException;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\SingletonFactory;
use wcf\system\WCF;

/**
 * Handles and tracks object visits.
 * 
 * @author	Marcel Werk
 * @copyright	2001-2012 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.visitTracker
 * @subpackage	system.visitTracker
 * @category	Community Framework
 */
class VisitTracker extends SingletonFactory {
	/**
	 * default tracking lifetime
	 * @var	integer
	 */
	const DEFAULT_LIFETIME = 604800; // = one week
	
	/**
	 * list of available object types
	 * @var	array
	 */
	protected $availableObjectTypes = array();
	
	/**
	 * user visits
	 * @var	array
	 */
	protected $userVisits = null;
	
	/**
	 * @see	wcf\system\SingletonFactory::init()
	 */
	protected function init() {
		$this->availableObjectTypes = ObjectTypeCache::getInstance()->getObjectTypes('com.woltlab.wcf.visitTracker.objectType');
	}
	
	/**
	 * Return the id of the object type with the given name.
	 * 
	 * @param	string		$objectType
	 * @return	integer
	 */
	public function getObjectTypeID($objectType) {
		if (!isset($this->availableObjectTypes[$objectType])) {
			throw new SystemException("unknown object type '".$objectType."'");
		}
		
		return $this->availableObjectTypes[$objectType]->objectTypeID;
	}
	
	/**
	 * Returns the last visit time of the given object type for the active user.
	 * 
	 * @param	string		$objectType
	 * @return	integer
	 */
	public function getVisitTime($objectType) {
		$objectTypeID = $this->getObjectTypeID($objectType);
		
		if ($this->userVisits === null) {
			if (WCF::getUser()->userID) {
				// get data from storage
				UserStorageHandler::getInstance()->loadStorage(array(WCF::getUser()->userID));
						
				// get ids
				$data = UserStorageHandler::getInstance()->getStorage(array(WCF::getUser()->userID), 'trackedUserVisits');
					
				// cache does not exist or is outdated
				if ($data[WCF::getUser()->userID] === null) {
					$this->userVisits = array();
					$sql = "SELECT 	objectTypeID, visitTime
						FROM 	wcf".WCF_N."_tracked_visit_type
						WHERE	userID = ?";
					$statement = WCF::getDB()->prepareStatement($sql);
					$statement->execute(array(WCF::getUser()->userID));
					while ($row = $statement->fetchArray()) {
						$this->userVisits[$row['objectTypeID']] = $row['visitTime'];
					}
					
					// update storage data
					UserStorageHandler::getInstance()->update(WCF::getUser()->userID, 'trackedUserVisits', serialize($this->userVisits), 1);
				}
				else {
					$this->userVisits = @unserialize($data[WCF::getUser()->userID]);
				}
			}
			else {
				$this->userVisits = WCF::getSession()->getVar('trackedUserVisits');
			}
			
			if (!$this->userVisits) {
				$this->userVisits = array();
			}
		}
		
		if (isset($this->userVisits[$objectTypeID])) {
			return $this->userVisits[$objectTypeID];
		}
		
		if ($this->availableObjectTypes[$objectType]->lifetime) {
			return TIME_NOW - $this->availableObjectTypes[$objectType]->lifetime;
		}
		
		return TIME_NOW - self::DEFAULT_LIFETIME;
	}
	
	/**
	 * Returns the last visit time of the object of the given object type and
	 * with the given id for the active user.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$objectID
	 * @return	integer
	 */
	public function getObjectVisitTime($objectType, $objectID) {
		$visiTimes = $this->getObjectVisitTimes($objectType, array($objectID));
		
		return $visiTimes[$objectID];
	}
	
	/**
	 * Returns the last visit times of the objects of the given object type
	 * and with the given ids for the active user.
	 * 
	 * @param	string			$objectType
	 * @param	array<integer>		$objectIDs
	 * @return	array<integer>
	 */
	public function getObjectVisitTimes($objectType, array $objectIDs) {
		$visitTimes = array();
		
		if (WCF::getUser()->userID) {
			$conditionBuilder = new PreparedStatementConditionBuilder();
			$conditionBuilder->add("objectTypeID = ?", array($this->getObjectTypeID($objectType)));
			$conditionBuilder->add("userID = ?", array(WCF::getUser()->userID));
			$conditionBuilder->add("objectID IN (?)", array($objectIDs));
			
			$sql = "SELECT	objectID, visitTime
				FROM	wcf".WCF_N."_tracked_visit
				".$conditionBuilder;
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute($conditionBuilder->getParameters());
			
			while ($row = $statement->fetchArray()) {
				$visitTimes[$row['objectID']] = $row['visitTime'];
			}
		}
		else {
			$objectTypeID = $this->getObjectTypeID($objectType);
			
			foreach ($objectIDs as $objectID) {
				$visitTime = WCF::getSession()->getVar('trackedUserVisit_'.$objectTypeID.'_'.$objectID);
				if ($visitTime) {
					$visitTimes[$objectID] = $visitTime;
				}
			}
		}
		
		if (count($visitTimes) != count($objectIDs)) {
			$objectTypeVisitTime = $this->getVisitTime($objectType);
			
			foreach ($objectIDs as $objectID) {
				if (!isset($visitTimes[$objectID])) {
					$visitTimes[$objectID] = $objectTypeVisitTime;
				}
			}
		}
		
		return $visitTimes;
	}
	
	/**
	 * Deletes all tracked visits of the object type with the given object type.
	 * 
	 * @param	string		$objectType
	 */
	public function deleteObjectVisits($objectType) {
		if (WCF::getUser()->userID) {
			$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
				WHERE		objectTypeID = ?
						AND userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), WCF::getUser()->userID));
		}
	}
	
	/**
	 * Tracks an object visit.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$objectID
	 * @param	integer		$time
	 */
	public function trackObjectVisit($objectType, $objectID, $time = TIME_NOW) {
		if (WCF::getUser()->userID) {
			// delete old visit
			$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
				WHERE		objectTypeID = ?
						AND objectID = ?
						AND userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), $objectID, WCF::getUser()->userID));
			
			// save visit
			$sql = "INSERT INTO	wcf".WCF_N."_tracked_visit
						(objectTypeID, objectID, userID, visitTime)
				VALUES		(?, ?, ?, ?)";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), $objectID, WCF::getUser()->userID, $time));
		}
		else {
			WCF::getSession()->register('trackedUserVisit_'.$this->getObjectTypeID($objectType).'_'.$objectID, $time);
		}
	}
	
	/**
	 * Tracks an object type visit.
	 * 
	 * @param	string		$objectType
	 * @param	integer		$time
	 */
	public function trackTypeVisit($objectType, $time = TIME_NOW) {
		if (WCF::getUser()->userID) {
			// delete old visit
			$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit_type
				WHERE		objectTypeID = ?
						AND userID = ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), WCF::getUser()->userID));
			
			// save visit
			$sql = "INSERT INTO	wcf".WCF_N."_tracked_visit_type
						(objectTypeID, userID, visitTime)
				VALUES		(?, ?, ?)";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), WCF::getUser()->userID, $time));
			
			// delete obsolete object visits
			$sql = "DELETE FROM	wcf".WCF_N."_tracked_visit
				WHERE		objectTypeID = ?
						AND userID = ?
						AND visitTime <= ?";
			$statement = WCF::getDB()->prepareStatement($sql);
			$statement->execute(array($this->getObjectTypeID($objectType), WCF::getUser()->userID, $time));
			
			// reset storage
			UserStorageHandler::getInstance()->reset(array(WCF::getUser()->userID), 'trackedUserVisits', 1);
		}
		else {
			$this->getVisitTime($objectType);
			$this->userVisits[$this->getObjectTypeID($objectType)] = $time;
			WCF::getSession()->register('trackedUserVisits', $this->userVisits);
		}
	}
}
