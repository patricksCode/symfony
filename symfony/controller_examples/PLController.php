<?php

namespace Application\PAdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Application\PLibBundle\classes\MediaManager;
use Application\PLibBundle\classes\Aggies\PropertyAggie;
use Application\PLibBundle\Entity\Property;
use Application\PLibBundle\Entity\PropertyContactAssociation;
use Application\PLibBundle\Entity\Address;
use Application\PLibBundle\Entity\PhoneNumber;

class PLController extends Controller
{
	protected static function getMediasForJSON($pl)
	{
		$medias = new \stdClass();
		foreach ($pl->getMedias() as $currMedia) {
			$id = ($currMedia->getId() ?: $currMedia->getTempId());
			$medias->{$id} = $currMedia->getDescription();
		}
		return $medias;
	}

	protected static function getAvailablePhoneTypes($em){
		$availablePhoneTypes = new \stdClass();
		$phoneTypes = $em->createQuery('SELECT pt FROM Application\PLibBundle\Entity\PhoneNumberType pt')->getResult();
		foreach ($phoneTypes as $currPhoneType) {
			$availablePhoneTypes->{$currPhoneType->getId()} = $currPhoneType->getName();
		}

		return $availablePhoneTypes;
	}

	protected static function getContactsForJSON($db, $pl)
	{
		$contacts = new \stdClass();

		$contactAssociations = ($pl instanceof Property || $pl instanceof Listing ? $pl->getContactAssociations() : $pl->getContacts());
		foreach ($contactAssociations as $currContactAssociation) {
			$currContact = ($pl instanceof Property || $pl instanceof Listing ? $currContactAssociation->getContact() : $currContactAssociation);

			// if the contact hasn't been added yet, it has no id so use the temporary ids
			$name = $currContact->getLastName();
			if ($currContact->getFirstName()) {
				$name = $currContact->getFirstName().' '.$currContact->getLastName();
			}

			$id = ($currContact->getId() ? : $currContact->getTempId());

			if (isset($contacts->{$id})) {
				// we've already added this contact previously because of another type
				// no need to add it again, just need to append the type
				$contacts->{$id}['types'][] = array(
					'typeId' => $currContactAssociation->getType()->getId(),
					'type' => $currContactAssociation->getType()->getName(),
				);
				continue;
			}

			$contacts->{$id} = array(
				'isUser' => ($currContact->getUserId() != 0 ? 1 : 0),
				'name' => $name,
				'company' => ($currContact->getCompany() ? : ''),
				'email' => ($currContact->getEmail() ? : ''),
			);

			if ($pl instanceof Property) {
				$contacts->{$id}['types'] = array(
					array(
						'typeId' => $currContactAssociation->getType()->getId(),
						'type' => $currContactAssociation->getType()->getName(),
					),
				);
			}

			if ($address = $currContact->getAddress()) {
				$address1 = $currContact->getAddress()->getStreet();
				if ($currContact->getAddress()->getNumber()) {
					$address1 = $currContact->getAddress()->getNumber().' '.$currContact->getAddress()->getStreet();
				}

				$contacts->{$id}['address'] = array(
					'address1' => $address1,
					'address2' => $currContact->getAddress()->getAdditionalMailingLine(),
					'city' => $currContact->getAddress()->getCity(),
					'state' => $currContact->getAddress()->getState()?$currContact->getAddress()->getState()->getAbbreviation():null,
					'zip' => $currContact->getAddress()->getZip(),
					'country' => $currContact->getAddress()->getState()?$currContact->getAddress()->getState()->getCountry()->getAbbreviation():null,
				);
			}

			$phoneObjects = $currContact->getPhoneNumbers();
			if (count($phoneObjects)) {
				$contacts->{$id}['phoneNumbers'] = array();
				foreach ($phoneObjects as $currPhoneObj) {
					$contacts->{$id}['phoneNumbers'][] = array(
						'type' => $currPhoneObj->getPhoneNumberType()->getName(),
						'typeId' => $currPhoneObj->getPhoneNumberType()->getId(),
						'number' => $currPhoneObj->getNumber(),
						'id' => $currPhoneObj->getId(),
					);
				}
			}
		}

		return $contacts;
	}

	protected function setMediaFromPost(&$pl, $post, $forPreview=false)
	{
		$em = $this->get('doctrine.orm.entity_manager');

		$photos = $post->get('photos');
		$photos = json_decode($photos, true);
		if (is_array($photos)) {
			foreach ($photos as $currPhotoId => $currPhotoDescription) {
				if (is_numeric($currPhotoId)) {
					foreach ($pl->getMedias() as $currMedia) {
						if ($currMedia->getId() == $currPhotoId) {
							$currMedia->setDescription($currPhotoDescription);
						}
					}
				} else {
					$mediaManager = new MediaManager($this->container, $currPhotoId);
					$media = $mediaManager->createMedia($currPhotoDescription, $forPreview);
					$pl->addMedias($media);
				}
			}
		}

		$removePhotoIds = $post->get('removePhotoIds');
		if (is_array($removePhotoIds)) {
			foreach ($removePhotoIds as $currPhotoId) {
				$currPhoto = $em->find('Application\PLibBundle\Entity\Media', $currPhotoId);
				$pl->removeMedias($currPhoto);
			}
		}
	}

	public function applyContactAssociations(&$property, $contact, $post)
	{
		$em = $this->get('doctrine.orm.entity_manager');

		$types = array();
		foreach ($post['types'] as $currType) {
			$types[] = $currType['typeId'];
		}

		$typesAssociated = array();
		$contactAssociations = $em->getRepository('Application\PLibBundle\Entity\PropertyContactAssociation')->findBy(array(
			'contact' => $contact->getId(),
			'property' => $property->getId(),
		));
		foreach ($contactAssociations as $currContactAssociation) {
			$typesAssociated[] = $currContactAssociation->getType()->getId();
		}

		$typesToRemove = array_diff($typesAssociated, $types);
		$typesToAdd = array_diff($types, $typesAssociated);

		foreach ($typesToAdd as $currTypeId) {
			$currType = $em->find('Application\PLibBundle\Entity\ContactType', $currTypeId);
			$currAssociation = new PropertyContactAssociation();
			$currAssociation->setContact($contact);
			$currAssociation->setType($currType);
			$property->addContactAssociations($currAssociation);
		}

		foreach ($typesToRemove as $currTypeId) {
			$currType = $em->find('Application\PLibBundle\Entity\ContactType', $currTypeId);
			$property->removeContacts($contact, $currType);
		}
	}

	public function applyContactChanges(&$contact, $post, $controller=NULL)
	{
		if($controller){
			$em = $controller->get('doctrine.orm.entity_manager');
		}
		else{
			$em = $this->get('doctrine.orm.entity_manager');
		}

		unset($post['types']);

		$post['firstName'] = $post['name'];
		$post['lastName'] = '';
		$nameParts = explode(' ', trim($post['name']));
		if (count($nameParts) > 1) {
			$post['firstName'] = array_shift($nameParts);
			$post['lastName'] = implode(' ', $nameParts);
		}
		unset($post['name']);

		$address = $contact->getAddress();
		if (isset($post['address'])) {
			if (empty($address)) {
				$address = new Address;
				$contact->setAddress($address);
			}

			$post['address']['number'] = '';
			$post['address']['street'] = $post['address']['address1'];
			$addressParts = explode(' ', $post['address']['address1']);
			if (count($addressParts) > 1) {
				$post['address']['number'] = array_shift($addressParts);
				$post['address']['street'] = implode(' ', $addressParts);
			}
			unset($post['address']['address1']);

			$post['address']['additionalMailingLine'] = $post['address']['address2'];
			unset($post['address']['address2']);

			foreach ($post['address'] as $key => $value) {
				$getFunction = 'get'.ucwords($key);
				$setFunction = 'set'.ucwords($key);

				// we are not storing this as a string
				if ($key == 'state') {
					if(!empty($value)){
						$state = $em->getRepository('Application\PLibBundle\Entity\State')->findOneByAbbreviation($value);
						if (!empty($state)) {
							$address->$setFunction($state);
						}

						continue;
					}

				}

				if (method_exists($address, $getFunction)) {
					if ($address->$getFunction() !== $value) {
						$address->$setFunction($value);
					}
				}
			}
		} else {
			if (!empty($address)) {
				$contact->removeAddress();
			}
		}
		unset($post['address']);

		if (isset($post['phoneNumbers'])) {

			foreach ($post['phoneNumbers'] as $currPhoneNumber) {
				$phoneNumberType = $em->find('Application\PLibBundle\Entity\PhoneNumberType', $currPhoneNumber['typeId']);
				if (empty($phoneNumberType)) {
					continue;
				}

				if (!preg_match('/^([\+][0-9]{1,3}[ \.\-])?([\(]{1}[0-9]{2,6}[\)])?([0-9 \.\-\/]{3,20})((x|ext|extension)[ ]?[0-9]{1,4})?$/', $currPhoneNumber['number'])) {
					continue;
				}

				if (substr($currPhoneNumber['id'], 0, 1) == 'n') {
					$phoneNumber = new PhoneNumber();
				} else {
					$existingPhoneNumbers = $contact->getPhoneNumbers();
					foreach ($existingPhoneNumbers as $currExistingPhoneNumber) {
						if ($currExistingPhoneNumber->getId() == $currPhoneNumber['id']) {
							$phoneNumber = $currExistingPhoneNumber;
							break;
						}
					}
				}

				if (empty($phoneNumber)) {
					continue;
				}

				if (isset($currPhoneNumber['is_deleted']) && $currPhoneNumber['is_deleted'] == 1) {
					if (substr($currPhoneNumber['id'], 0, 1) != 'n') {
						$contact->removePhoneNumbers($phoneNumber);
						continue;
					}
				}

				$phoneNumber->setPhoneNumberType($phoneNumberType);
				$phoneNumber->setNumber($currPhoneNumber['number']);
				if (substr($currPhoneNumber['id'], 0, 1) == 'n') {
					$contact->addPhoneNumbers($phoneNumber);
				}
			}
		}
		unset($post['phoneNumbers']);

		foreach ($post as $key => $value) {
			$getFunction = 'get'.ucwords($key);
			$setFunction = 'set'.ucwords($key);
			if (method_exists($contact, $getFunction)) {
				if ($contact->$getFunction() !== $value) {
					$contact->$setFunction($value);
				}
			}
		}
	}

	public function removeContactsFromPost(&$pl, $post)
	{
		$em = $this->get('doctrine.orm.entity_manager');
		$removeContactIds = $post->get('removeContactIds');
		if (is_array($removeContactIds)) {
			foreach ($removeContactIds as $currContactId) {
				$currContact = $em->find('Application\PLibBundle\Entity\Contact', $currContactId);
				$pl->removeContacts($currContact);
			}
		}
	}
}
