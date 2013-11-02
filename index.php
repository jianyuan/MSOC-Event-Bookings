<?php
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require_once __DIR__.'/vendor/autoload.php';

$app = new Silex\Application();

$app['env'] = ($_SERVER['HTTP_HOST'] === 'localhost') ? 'dev' : 'prod';

$app->register(new Silex\Provider\MonologServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider());
$app->register(new Silex\Provider\DoctrineServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider());
$app->register(new Silex\Provider\FormServiceProvider());
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\SessionServiceProvider());

// Load config
$app->register(new Igorw\Silex\ConfigServiceProvider(__DIR__.'/config.php'));

if ($app['env'] === 'dev') {
	$app->register(new Igorw\Silex\ConfigServiceProvider(__DIR__.'/config_dev.php'));
}

// Set default timezone
date_default_timezone_set($app['timezone']);

if ($app['debug'] === true) {
	$app['twig']->addExtension(new Twig_Extension_Debug());
}

if ($app['env'] !== 'prod')
{
	function pam_auth($user, $pass)
	{
		return ($user == $pass);
	}

	function ldap_get_name($user)
	{
		return 'Jian Lee';
	}

	function ldap_get_mail($user)
	{
		return 'jyl111@imperial.ac.uk';
	}
	
	function ldap_get_info($user)
	{
		return array(
			'Electrical & Electronic Engineering (MEng 4YFT)',
			'Undergraduate',
			'Electrical and Electronic Engineering',
			'Engineering',
			'Imperial College'
		);
	}
}
// else
// {
// 	$user = $app['session']->get('user');
// 	if ($user && $user['username']) {
// 		$app['debug'] = true;
// 	}

// }

// temporarily disabled membership crawler
$app['membership_crawler'] = $app->protect(function() use ($app) {
	return array();
});

// require_once 'icucrawler.php';

// $app['membership_crawler'] = $app->protect(function() use ($app) {
// 	require_once 'Zend/Loader/Autoloader.php';
// 	Zend_Loader_Autoloader::getInstance();
	
// 	try {
// 		$members = $app['icums_members'];
// 	} catch (Exception $e) {
// 		return false;
// 	}
	
// 	if ( ! empty($members)) {
// 		$app['db']->query('TRUNCATE TABLE memberships');
		
// 		foreach ($members as $member) {
// 			$app['db']->insert('memberships', array(
// 				'username' => $member['login'],
// 				'registered' => DateTime::createFromFormat('d/m/Y', $member['date'])->format('Y-m-d'),
// 			));
// 		}
// 	}
	
// 	return true;
// });

$checkLogin = function() use ($app) {
	$user = $app['session']->get('user');
	
	if ($user === null || ! ldap_get_info($user['username'])) {
		return new RedirectResponse($app['url_generator']->generate('login'));
	}
};

$checkCommittee = function() use ($app) {
	$user = $app['session']->get('user');
	
	if ($user === null || ! in_array($user['username'], $app['msoc']['committee_members'])) {
		if ($user) {
			$app['monolog']->addWarning(sprintf("User '%s' tried to access committee members only page.", $user['username'], $event['name']));
		}
		$app->abort(403, 'Committee members only!');
	}
};

$eventProvider = function($event) use ($app) {
	$user = $app['session']->get('user');

	if ( ! is_array($event)) {
		$event = $app['db']->fetchAssoc('SELECT * FROM events WHERE slug = ? AND is_active = 1', array($event));
	}

	if ( ! $event) {
		$app->abort(404, 'Event does not exist.');
	}

	$event['total_booked'] = $app['db']->fetchColumn('SELECT COUNT(*) FROM places WHERE event_id = ?', array($event['id']));
	$event['fully_booked'] = ($event['total_booked'] >= $event['places']);
	$event['places_left'] = $event['places'] - $event['total_booked'];
	if ($event['places_left'] < 0) {
		$event['places_left'] = 0;
	}

	$event['booking_opened'] = (strtotime($event['opening_time']) <= time());
	$event['can_book'] = ($event['booking_opened'] && ( ! $event['fully_booked'] || $event['allow_overbook']));
	$event['booking'] = $app['db']->fetchAssoc('SELECT * FROM places WHERE event_id = ? AND username = ?', array($event['id'], $user['username']));

	if ($event['booking']) {
		$event['booking']['queue'] = $app['db']->fetchColumn('SELECT COUNT(*) FROM places WHERE event_id = ? AND id <= (SELECT id FROM places WHERE event_id = ? AND username = ?)', array($event['id'], $event['id'], $user['username']));
	}

	return $event;
};

/**
 * GET /logout
 */
$app->get('/logout', function() use ($app) {
	$app['session']->remove('user');
	return $app->redirect($app['url_generator']->generate('login'));
})
->bind('logout');

/**
 * GET /events
 */
$app->get('/events', function() use ($app, $eventProvider) {
	$user = $app['session']->get('user');

	$unbookedEvents = $app['db']->fetchAll('SELECT * FROM events WHERE is_active = 1 AND id NOT IN (SELECT event_id FROM places WHERE username = ?) ORDER BY opening_time ASC', array($user['username']));
	$bookedEvents   = $app['db']->fetchAll('SELECT * FROM events WHERE is_active = 1 AND id 	IN (SELECT event_id FROM places WHERE username = ?) ORDER BY opening_time ASC', array($user['username']));

	foreach ($unbookedEvents as $key => $event) {
		$unbookedEvents[$key] = $eventProvider($event);
	}
	foreach ($bookedEvents as $key => $event) {
		$bookedEvents[$key] = $eventProvider($event);
	}

	return $app['twig']->render('events.twig', array(
		'unbookedEvents' => $unbookedEvents,
		'bookedEvents' => $bookedEvents,
	));
})
->bind('events')
->before($checkLogin);

/**
 * GET /book/{event}
 */
$app->get('/book/{event}', function($event) use ($app) {
	$user = $app['session']->get('user');

	$form = $app['form.factory']->createBuilder('form')
		->getForm();

	return $app['twig']->render('book.twig', array(
		'event' => $event,
		'form' => $form->createView(),
	));
})
->bind('book')
->before($checkLogin)
->convert('event', $eventProvider);

/**
 * POST /book/{event}
 */
$app->post('/book/{event}', function($event) use ($app) {
	$user = $app['session']->get('user');

	if ( ! $event['booking_opened']) {
		$app['monolog']->addWarning(sprintf("User '%s' booked '%s' when booking is closed.", $user['username'], $event['name']));
		$app->abort(500, 'Booking hasn\'t even opened yet!');
	}

	if ( ! $event['can_book']) {
		$app['monolog']->addWarning(sprintf("User '%s' booked '%s' when is already full.", $user['username'], $event['name']));
		return $app['twig']->render('cannot_book.twig', array(
			'event' => $event,
		));
	}

	$user = $app['session']->get('user');

	$alreadyBooked = $app['db']->fetchColumn('SELECT COUNT(*) FROM places WHERE username = ? AND event_id = ?', array($user['username'], $event['id']));

	if ($alreadyBooked) {
		$app['monolog']->addWarning(sprintf("User '%s' booked '%s' when already booked.", $user['username'], $event['name']));
		return $app->redirect($app['url_generator']->generate('booking_details', array('event' => $event['slug'])));
	}

	$booking = array(
		'username' => $user['username'],
		'event_id' => $event['id'],
		'time' => date('Y-m-d H:i:s'),
	);

	$app['db']->insert('places', $booking);

	$event['booking'] = $booking;
	$event['booking']['id'] = $app['db']->lastInsertId();
	$event['booking']['queue'] = $app['db']->fetchColumn('SELECT COUNT(*) FROM places WHERE event_id = ? AND id <= ?', array($event['id'], $event['booking']['id']));

	$app['monolog']->addInfo(sprintf("User '%s' booked '%s', place #%d.", $user['username'], $event['name'], $event['booking']['id']));

	return $app['twig']->render('booking_details.twig', array(
		'event' => $event,
		'showSuccess' => true,
	));
})
->before($checkLogin)
->convert('event', $eventProvider);

/**
 * GET /book/{event}/details
 */
$app->get('/book/{event}/details', function($event) use ($app) {
	$user = $app['session']->get('user');

	if ( ! $event['booking']) {
		$app['monolog']->addWarning(sprintf("User '%s' viewed non-existent booking '%s'.", $user['username'], $event['name']));
		$app->abort(404, 'Booking does not exist.');
	}

	$numberInQueue = $app['db']->fetchColumn('SELECT COUNT(*) FROM places WHERE event_id = ? AND id <= (SELECT id FROM places WHERE event_id = ? AND username = ?)', array($event['id'], $event['id'], $user['username']));

	return $app['twig']->render('booking_details.twig', array(
		'event' => $event,
		'numberInQueue' => $numberInQueue,
	));
})
->bind('booking_details')
->before($checkLogin)
->convert('event', $eventProvider);

/**
 * GET /update_counters/{event}
 * Update and get total active
 * Get places left
 */
$app->get('/update_counters/{event}', function($event) use ($app) {
	$user = $app['session']->get('user');

	$query = 'REPLACE INTO last_actives (username, event_id, time) VALUES (?, ?, ?)';
	$app['db']->executeUpdate($query, array($user['username'], $event['id'], time()));

	$online = (int) $app['db']->fetchColumn('SELECT COUNT(*) FROM last_actives WHERE event_id = ? AND time >= ?', array(
		$event['id'],
		time() - $app['msoc']['timer_delta'],
	));

	return $app->json(array(
		'online' => $online,
		'placesLeft' => $event['places_left'],
		//'places' => (int) $event['places'],
		'placesPercentage' => ($event['places_left'] / $event['places'] * 100),
		'allowBooking' => $event['can_book'],
		'serverTime' => date('M j, Y H:i:s O'),
	));
})
->bind('update_counters')
->before($checkLogin)
->convert('event', $eventProvider);

/**
 * GET /me
 * My Info
 */
$app->get('/me', function() use ($app) {
	$user = $app['session']->get('user');

	return $app['twig']->render('me.twig', array(
		'name' => ldap_get_name($user['username']),
		'email' => ldap_get_mail($user['username']),
		'info' => ldap_get_info($user['username']),
		'membership' => $app['db']->fetchAssoc('SELECT * FROM memberships WHERE username = ?', array($user['username'])),
	));
})
->bind('me')
->before($checkLogin);

/**
 * ANY /login
 * Login
 */
$app->match('/login', function(Request $request) use ($app) {
	$form = $app['form.factory']->createBuilder('form')
		->add('username', 'text', array(
			'constraints' => new Assert\NotBlank()
		))
		->add('password', 'password', array(
			'constraints' => new Assert\NotBlank()
		))
		->getForm();
	
	if ($request->isMethod('POST')) {
		$form->bind($request);
		
		if ($form->isValid()) {
			$data = $form->getData();
			$data['username'] = strtolower($data['username']);
			
			if (pam_auth($data['username'], $data['password'])) {
				$app['session']->set('user', array(
					'username' => $data['username'],
					'is_committee' => in_array($data['username'], $app['msoc']['committee_members']),
				));
				$app['monolog']->addInfo(sprintf("User '%s' logged in.", $data['username']));
				return $app->redirect($app['url_generator']->generate('homepage'));
			} else {
				$form->addError(new FormError('Invalid login credentials. Please make sure that you login using your Imperial College ID and password.'));
			}
		}
	}

	return $app['twig']->render('login.twig', array(
		'form' => $form->createView(),
	));
})
->bind('login')
->requireHttps();


/**
 * GET /
 * homepage
 */
$app
	->get('/', function() use ($app) {
		$user = $app['session']->get('user');

		if ($app['msoc']['check_membership']) {
			$hasMembership = $app['db']->fetchColumn('SELECT COUNT(*) FROM memberships WHERE username = ?', array($user['username']));

			if ( ! $hasMembership) {
				$app['membership_crawler']();
				// Recheck for membership
				$hasMembership = $app['db']->fetchColumn('SELECT COUNT(*) FROM memberships WHERE username = ?', array($user['username']));
			}

			if ( ! $hasMembership) {
				$app['monolog']->addWarning(sprintf("User '%s' has no membership.", $user['username']));
				return $app['twig']->render('no_membership.twig');
			}
		}
		
		return $app->redirect($app['url_generator']->generate('events'));
	})
	->bind('homepage')
	->before($checkLogin);

/**
 * GET /admin
 * Admin
 */
$app->get('/admin', function() use ($app) {
	return $app['twig']->render('admin.twig', array(
		'events' => $app['db']->fetchAll('SELECT * FROM events'),
	));
})
->bind('admin')
->before($checkLogin)
->before($checkCommittee);

/**
 * GET /admin/members
 * View registered ICUMS members
 */
$app->get('/admin/members', function() use ($app) {
	$members = $app['db']->fetchAll('SELECT * FROM memberships');
	
	foreach ($members as $key => $row) {
		$members[$key]['name'] = ldap_get_name($row['username']);
		$members[$key]['email'] = ldap_get_mail($row['username']);
	}
	
	return $app['twig']->render('admin_members.twig', array(
		'members' => $members,
	));
})
->bind('admin_members')
->before($checkLogin)
->before($checkCommittee);

/**
 * GET /admin/last_actives
 * View last active users
 */
$app->get('/admin/last_actives', function() use ($app) {
	$users = $app['db']->fetchAll('SELECT * FROM last_actives ORDER BY time DESC');
	
	foreach ($users as $key => $row) {
		$users[$key]['name'] = ldap_get_name($row['username']);
		$users[$key]['email'] = ldap_get_mail($row['username']);
	}
	
	return $app['twig']->render('admin_last_actives.twig', array(
		'users' => $users,
	));
})
->bind('admin_last_actives')
->before($checkLogin)
->before($checkCommittee);

/**
 * GET /admin/event/{id}.csv
 * View bookings for a particular event
 */
$app->get('/admin/event/{id}.csv', function($id) use ($app) {
	$event = $app['db']->fetchAssoc('SELECT * FROM events WHERE id = ?', array($id));
	
	if ( ! $event) {
		$app->abort(404, 'Event does not exist.');
	}
	
	$places = $app['db']->fetchAll('
		SELECT places.*, COUNT(memberships.username) AS has_membership
		FROM places
		LEFT JOIN memberships ON memberships.username = places.username
		WHERE places.event_id = ?
		GROUP BY places.id', array($event['id']));
	
	if ( ! empty($places)) {
		foreach ($places as $key => $row) {
			$places[$key]['name'] = ldap_get_name($row['username']);
			$places[$key]['email'] = ldap_get_mail($row['username']);
		}
	}
	
	return new Response($app['twig']->render('admin_event.csv.twig', array(
		'places' => $places,
	)), 200, array('Content-Type' => 'text/csv'));
})
->bind('admin_event_csv')
->before($checkLogin)
->before($checkCommittee);

/**
 * GET /admin/event/{id}
 * View bookings for a particular event
 */
$app->get('/admin/event/{id}', function($id) use ($app) {
	$event = $app['db']->fetchAssoc('SELECT * FROM events WHERE id = ?', array($id));
	
	if ( ! $event) {
		$app->abort(404, 'Event does not exist.');
	}
	
	$places = $app['db']->fetchAll('
		SELECT places.*, COUNT(memberships.username) AS has_membership
		FROM places
		LEFT JOIN memberships ON memberships.username = places.username
		WHERE places.event_id = ?
		GROUP BY places.id', array($event['id']));
	
	if ( ! empty($places)) {
		foreach ($places as $key => $row) {
			$places[$key]['name'] = ldap_get_name($row['username']);
			$places[$key]['email'] = ldap_get_mail($row['username']);
		}
	}
	
	return $app['twig']->render('admin_event.twig', array(
		'event' => $event,
		'places' => $places,
	));
})
->bind('admin_event')
->before($checkLogin)
->before($checkCommittee);

$app->run();
