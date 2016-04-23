<?php

namespace Application\PAppBundle\Controller;

use Application\PLibBundle\classes\Aggies\ListingAggie;
use Application\PLibBundle\Email\PEmail;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use Application\PLibBundle\Controller\ReportController;



class ListingController extends Controller
{
	public function indexAction($id)
	{
		
		//the next 4 lines make sure there is a listing before attempting to display the page.
		$listing = $this->get('doctrine.orm.read_only_entity_manager')->find('PLibBundle:Listing',$id );		
		
		if(!$listing) {
			throw new NotFoundHttpException('Listing does not exist');	
		}
		
		// the following lines checks to make sure you are an admin before it displays a listing is isnt active  
		if (!$this->get('security.context')->isGranted('ROLE_ADMIN') &&  !$listing->getIsActive()) {
			throw new NotFoundHttpException('Listing does not exist');
		}
		
		// the next line makes sure that the reportObject variable in the session has been instantiated before attempting to get the value.
		// if not it is assigned an empty array.
		$report = is_array($this->getRequest()->getSession()->get('reportObjects'))?$this->getRequest()->getSession()->get('reportObjects'):array();
		
		// the next line checks to see if the current listing you are looking at is in the report.  This way I can toggle 
		// the addToReport/removeFromReport button.
		$inCart = in_array("l-".$listing->getId(), $report)?1:0;

		// I generate and set a custom csrf token.
		$csrfToken = md5(rand().time());
		$this->getRequest()->getSession()->set('ListingController:listingAction:token', $csrfToken);

		// I call another controller and dump the resuts to $subscribedListings;
		$subscribedListings = $this->forward('PApiBundle:Subscription:listListing')->getContent();
		
		// I json_encode $subscribedListings so I can easily manipulate the object with js/jquery
		$subscribedListings = json_decode($subscribedListings, true);
		
		// I check to see if this listing is subscribed too.
		$isSubscribedTo = in_array($listing->getId(), $subscribedListings);

		//I pass all the variables to the twig template.
		return $this->render('PAppBundle:Listing:index.twig.html', array(
			'type' =>'screen',
			'objType' => 'l',
			'inCart' => $inCart,
			'report' => $report,
			'listing' => $listing,
			'csrfToken' => $csrfToken,
			'inCart' => $inCart,
			'report' => $report,
			'isSubscribedTo' => $isSubscribedTo,
		));
				
	}
	
	// this controller is never called directly.  It handles the sending of an email to a listing contact via ajax.
	public function sendBrokerEmailAction(){
		
		// I instantiate all the variables i need.
		$bcc = array();
		$status = false;
		$contact = null;
		$emro = $this->get('doctrine.orm.read_only_entity_manager');
		$csrfToken = $this->getRequest()->request->get('csrfToken');
		
		//I load the listing that the email is refering too.
		$listing = $emro->getRepository('PLibBundle:Listing')->findOneBy(array('id'=>$this->getRequest()->request->get('listingId')));	
		
		//I assign an array of contact objects to $listingContacts.  Then I check to see if the contact I am emailing is a contact 
		// on this list.  Just to be sure.
		$listingContacts = $listing->getContacts();
		foreach($listingContacts as $currContact){
			if($this->getRequest()->request->get('contactId') == $currContact->getId()){
				$contact =  $currContact;
				break;
			}
		}
		
		
		// now I am making sure the contact object is an object and that the post was submitted from the form.
		if(is_object($contact) && ($csrfToken == $this->getRequest()->getSession()->get('ListingController:listingAction:token'))){
			
			// the next few lines generate the links I will need in the email.
			$listingLink = $this->get('router')->generate('listing', array('id' => $this->getRequest()->request->get('listingId')), true);
			$aboutUsLink = $this->get('router')->generate('about',array(), true);
			$registerLink = $this->get('router')->generate('login',array(), true);
			$sendingUser = $this->get('security.context')->getToken()->getUser();				
			
			// Here I am using dql to check to see if the user is a broker and assigning a boolean to $brokerIsUser.
			$query = $emro->createQuery("SELECT u FROM PLibBundle:User u JOIN u.contact c WHERE c.id=".$contact->getid());
			$brokerIsUser = count($query->getResult())?true:false;			
			
			// here I am instantiating a new mailer object and passing it the symfony2 service container
			$mailer = new PEmail($this->get('service_container'));
			
			// here I am creating an array of template variables.
			$templateVars =  array(
				'listingLink' => $listingLink,
				'aboutUsLink'=> $aboutUsLink,
				'registerLink' => $registerLink,
				'sender' => $sendingUser,
				'contact' => $contact,
				'message' => $this->getRequest()->request->get('message'),
				'subject' => $this->getRequest()->request->get('subject'),
				'displayName' => $listing->getDisplayName(),
				'brokerIsUser' => $brokerIsUser,
			);
			
			// here i render the email body and pass it the template variables.
			$body = $this->renderView('PAppBundle:Listing:brokerEmail.twig.html', $templateVars );
			
			// I check to see if I should bcc the logged in user.
			if($this->getRequest()->request->get('sendToSelf')){
				$bcc = array($sendingUser->getContact()->getEmail());
			}
			
			// Then I attempt to get the status of the email(if it was sent or not).
			$status = $mailer->sendEmail($contact->getEmail(), 'Message from Agorafy user', $body, $sendingUser->getContact()->getEmail(), "Agorafy", $bcc);
		}
		
		// I am returning the a jeson_encode response to the calling ajax function.
		return new Response(json_encode(array('status' => $status)));
		
	}


	
}
