<?php

namespace Totom\MainBundle\Controller\Content;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Totom\ContentBundle\Entity\Hotel\HotelContent;
use Totom\MainBundle\Controller\Controller;
use Totom\MainBundle\Entity\Hotel;
use Totom\MainBundle\Entity\HotelSearchCriteria;
use Totom\MainBundle\Entity\Package\Search\Package;
use Totom\MainBundle\Itinerary\ItineraryFormatter;
use Totom\MainBundle\Services\Response\Response as TotomServiceResponse;

class ContentController extends Controller
{
    /**
     * Display hotel details page for a hotel specified by slug.
     *
     * @param Request $request
     * @param string $_route
     * @param string $hotelId
     * @param string $hotelSlug
     * @return Response
     * @throws NotFoundHttpException
     */
    public function getHotelAction(Request $request, $_route, $hotelId, $hotelSlug)
    {
        /** @var HotelContent $hotelContent */
        $hotelContent = $this->findOneOr404(HotelContent::class, ['hotel' => $hotelId]);
        $hotel = $hotelContent->getHotel();
        if (!$hotel->isActive()) {
            throw $this->createNotFoundException('Unable to find instance of ' . Hotel::class);
        }
        $refinements = [
            'filters' => [
                'hotel' => [
                    ['value' => $hotel->getId()]
                ]
            ]
        ];

        if (!$hotelSlug || $hotelSlug != $hotelContent->getHotel()->getSlug()) {
            $params = [
                'hotelId' => $hotelId,
                'hotelSlug' => $hotelContent->getHotel()->getSlug()
            ];

            if ($request->query->has('search')) {
                // "package" and "search" parameters can not go together
                $request->query->remove('package');
            }
            $params += $request->query->all();

            return $this->redirectToRoute($_route, $params, Response::HTTP_MOVED_PERMANENTLY);
        }

        $hotelContentService = $this->get('hotel_content_service');
        $hotelData = $hotelContentService->getHotel($hotelId);

        // Attempting to do this the proper way with a sort function resulted in all the images
        // getting shuffled, even when they weren't supposed to be moved
        foreach ($hotelData['media'] as $i => $media) {
            if ($media['isHero'] === true) {
                unset($hotelData['media'][$i]);
                array_unshift($hotelData['media'], $media);
                $hotelData['media'] = array_values($hotelData['media']);
                break;
            }
        }

        // Remove result and thumb image
        foreach ($hotelData['media'] as $i => $media) {
            if ($media['isResult'] || $media['isPrimary']) {
                unset($hotelData['media'][$i]);
            }
        }

        // @todo: ensure this comes from content service
        $address = $hotelData['address'];
        $hotelData['getAddress'] = implode(', ', array_filter([$address['address'], $address['address2'], $address['city'], $address['postalCode']]));
        $hotelData['getAddressLines'] = array_filter([$address['address'], $address['address2'], $address['city'], $address['postalCode']]);

        $searchData = [];
        $isPackageSearch = $request->query->has('package');
        if (!$this->isFeatureEnabled('resort_details_v2')) {
            if ($request->query->has('search')) {
                $hotelSearchId = $request->query->get('search');
                $hotelSearch = $this->getHotelSearch($hotelSearchId, $refinements);

                if (!$hotelSearch) {
                    throw new NotFoundHttpException(sprintf('Unable to find search %s', $hotelSearchId));
                }

                if ($hotelSearch['numberOfTotalResults'] == 0 || !isset($hotelSearch['hotels'])) {
                    if ($hotelSearch['search']['destinationType'] !== HotelSearchCriteria::DESTINATION_TYPE_REGION) {
                        $hotelSearchId = $this->convertSearchToRegion($hotelSearch, $hotel);
                    }

                    return $this->redirect('/hotel/' . $hotelSearchId . '?nhif=1');
                }

                $searchData = $hotelSearch;
            } elseif ($request->query->has('package')) {
                $packageSearchId = $request->query->get('package');
                $packageSearch = $this->getPackageSearch($packageSearchId, $refinements);

                if (!$packageSearch) {
                    throw new NotFoundHttpException(sprintf('Unable to find package %s', $packageSearchId));
                }

                if ($packageSearch['numberOfTotalResults'] == 0 || !isset($packageSearch['hotels'])) {
                    return $this->redirect('/package/' . $packageSearchId);
                }

                $searchData = $packageSearch;
            }

            // Format availability
            if (isset($searchData['hotels'][0]['availabilities'])) {
                $this->formatAvailabilities($searchData['hotels'][0]['availabilities']);
            }
        }

        if (!$request->query->has('search') && !$request->query->has('package')) {
            if ($request->query->has('fromDate') && $request->query->has('toDate')) {
                $searchResponse = $this->createSearch($request, $hotelId);

                if ($searchResponse->isValid()) {
                    return $this->redirectToRoute('hotel_details', [
                        'hotelId' => $hotelId,
                        'hotelSlug' => $hotelSlug,
                        'search' => $searchResponse->getData()->getPublicId()
                    ]);
                }
            }
        }

        if (isset($packageSearch)) {
            $currentSearch = $searchData;
            $previousSearch = $packageSearch['search'];
            $lastSearchType = 'package';
        } elseif (isset($hotelSearch)) {
            $currentSearch = $searchData;
            $previousSearch = $hotelSearch['search'];
            $lastSearchType = 'hotel';
        } else {
            $currentSearch = null;
            list($lastSearchType, $previousSearch) = $this->getPreviousSearch($request);
        }

        $appData = [
            'hotelId' => $hotelData['id'],
            'hotelSlug' => $hotelData['slug'],
            'hotelName' => $hotelData['name'],
            'roomTypes' => $this->isFeatureEnabled('strikethrough_pricing') ? $hotelData['roomTypes'] : [],
            'destinationRegion' => $hotelData['primaryRegionId'],
            'searchData' => $searchData,
            'dynamicSearch' => false,
            'isPackage' => $isPackageSearch,
            'noDestination' => !(isset($hotelSearch) || isset($packageSearch)),
            'lastSearchType' => $lastSearchType,
        ];

        $this
            ->get('templating.globals')
            ->setAdRollProductId('h' . $hotel->getId());

        if ($this->isFeatureEnabled('resort_details_v2')) {
            $template = 'TotomMainBundle:Content:hotel_details_v2.html.twig';
        } else {
            $template = 'TotomMainBundle:Content:hotel_details.html.twig';
        }

        $response = $this->render($template, [
            'urlRoot' => $this->generateUrl($_route, ['hotelId' => $hotelId, 'hotelSlug' => $hotelSlug]),
            'hotel' => $hotelData,
            'appData' => $appData,
            'searchType' => 'explicit',
            'lastSearchType' => $lastSearchType,
            'previousSearchData' => [$lastSearchType => $previousSearch],
            'searchData' => $currentSearch,
            'preventPageSend' => true,
            'pageTitle' => $hotelData['name']
        ]);

        return $this->clearResponseCache($response);
    }

    /**
     * Group rooms of the same type in the same availability
     *
     * @param array $availabilities
     * @return array
     */
    private function formatAvailabilities(&$availabilities)
    {
        foreach ($availabilities as &$availability) {
            if (count($availability['rooms']) <= 1) {
                if (isset($availability['rooms'][0]['supplierPromo'])) {
                    $availability['rooms'][0]['supplierPromo']['description'] = ItineraryFormatter::formatPromoDescription($availability['rooms'][0]['supplierPromo']['description']);
                }
                continue;
            }

            $parsedRoomTypeIds = [];
            foreach ($availability['rooms'] as $key => &$room) {
                $existingKey = array_search($room['roomTypeId'], $parsedRoomTypeIds);
                if ($existingKey !== false) {
                    $existingRoom = &$availability['rooms'][$existingKey];
                    $existingRoom['quantity'] = isset($existingRoom['quantity']) ? (int)$existingRoom['quantity'] + 1 : 1;
                    unset($availability['rooms'][$key]);
                } else {
                    $availability['rooms'][$key]['quantity'] = 1;
                    $parsedRoomTypeIds[$key] = $room['roomTypeId'];
                }

                if (isset($room['supplierPromo'])) {
                    $room['supplierPromo']['description'] = ItineraryFormatter::formatPromoDescription($room['supplierPromo']['description']);
                }
            }
        }
    }

    /**
     * @param array $search
     * @param Hotel $hotel
     * @return string
     */
    private function convertSearchToRegion(array $search, Hotel $hotel)
    {
        $fromDate = new \DateTime($search['search']['fromDate']);
        $toDate = new \DateTime($search['search']['toDate']);

        $response = $this
            ->get('hotel_search_processor')
            ->createSearch(
                $fromDate,
                $toDate,
                $hotel->getPrimaryRegion()->getId(),
                HotelSearchCriteria::DESTINATION_TYPE_REGION,
                $search['search']['hotelRooms']
            );

        /** @var HotelSearchCriteria $search */
        $search = $response->getData();

        return $search->getPublicId();
    }

    /**
     * Fetch a hotel search given a search ID and refinements.
     *
     * @param string $hotelSearchId Search ID
     * @param array $refinements
     * @param int $page
     * @return array|bool
     */
    private function getHotelSearch($hotelSearchId, array $refinements = [], $page = 1)
    {
        $searchCriteria = $this
            ->getEntityManager()
            ->getRepository(HotelSearchCriteria::class)
            ->findOneBy(['publicId' => $hotelSearchId]);
        if (!$searchCriteria) {
            return false;
        }

        /** @var TotomServiceResponse $response */
        $response = $this
            ->get('hotel_search_processor')
            ->fetchSearch($searchCriteria, $refinements, $page);

        if (!$response->isValid()) {
            return false;
        }

        $hotelSearchResponse = $response->getData();
        $hotelSearchResponse['search'] = $this->getSerializer()->toArray($hotelSearchResponse['search']);

        // just has to be done since some things are persisted on fetchSearch
        $this->getEntityManager()->flush();

        return $hotelSearchResponse;
    }

    /**
     * Fetch a hotel search given a search ID and refinements.
     *
     * @param string $searchId
     * @param array $refinements
     * @param int $page
     * @param int|null $selectedFlightId
     * @return array|bool
     */
    private function getPackageSearch($searchId, array $refinements = [], $page = 1, $selectedFlightId = null)
    {
        /** @var TotomServiceResponse $response */
        $response = $this
            ->get('package_search_processor')
            ->fetchSearch($searchId, $refinements, $page, $selectedFlightId);

        if (!$response->isValid()) {
            return false;
        }

        $responseData = $response->getData();

        // just has to be done since some things are persisted on fetchSearch
        $this->getEntityManager()->flush();

        $flight = $responseData['flight'] ? $this->getSerializer()->toArray($responseData['flight'], ['showPricing' => false]) : null;

        return array_merge($responseData, [
            'search' => $this->getSerializer()->toArray($responseData['search']),
            'flight' => $flight
        ]);
    }

    /**
     * @param Request $request
     * @return \Totom\MainBundle\Services\Response\Response
     * @throws \InvalidArgumentException
     */
    protected function createSearch(Request $request, $arrivalLocation)
    {
        $rooms = $request->query->get('numRooms', 1);
        $adults = $request->query->get('numAdults', 2);
        $departureDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('fromDate'));
        $returnDate = \DateTime::createFromFormat('Y-m-d', $request->query->get('toDate'));
        $destinationType = $request->query->get('destinationType', 'hotel');

        if (!$departureDate || !$returnDate || $returnDate <= $departureDate || $departureDate < new \DateTime()) {
            return $this->get('response_factory')->createInvalidResponse('Invalid search criteria!');
        }

        $response = $this->get('hotel_search_processor')->createSearch(
            $departureDate,
            $returnDate,
            $arrivalLocation,
            $destinationType,
            $this->formatRooms($rooms, $adults)
        );
        if ($response->isValid()) {
            /** @var HotelSearchCriteria $search */
            $search = $response->getData();
            $this->getEntityManager()->persist($search);
            $this->getEntityManager()->flush();
        }
        return $response;
    }

    /**
     * returns number of adults evenly divided into the number of rooms with remainder in final room.
     * 6 adults in 2 rooms would be 3, 3
     * 10 adults in 3 rooms would be 4, 4, 2
     *
     * @param int $roomCount
     * @param int $adultCount
     * @return array
     */
    protected function formatRooms($roomCount, $adultCount)
    {
        $rooms = [];
        $averageAdultsPerRoom = ceil($adultCount / $roomCount);
        while ($adultCount > $averageAdultsPerRoom) {
            $rooms[] = ['numberOfAdults' => $averageAdultsPerRoom];
            $adultCount -= $averageAdultsPerRoom;
        }
        $rooms[] = ['numberOfAdults' => $adultCount];
        return $rooms;
    }
}
