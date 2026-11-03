<?php
namespace PersianAIAssistant\Extraction\Extractors;

use PersianAIAssistant\Extraction\Support\Content;
use PersianAIAssistant\Extraction\Support\ContactFinder;
use PersianAIAssistant\Extraction\Support\Html;
use PersianAIAssistant\Extraction\Support\JsonLd;
use PersianAIAssistant\Extraction\Support\Normalizer;
use PersianAIAssistant\Extraction\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * اطلاعات تماس از چهار منبع: برگه‌های «تماس با ما»، صفحهٔ عمومی سایت (هدر/فوتر + schema.org)،
 * تنظیمات فروشگاه ووکامرس. همه در یک درخواست انجام می‌شود (تعداد برگه‌ها کم است).
 */
final class ContactExtractor implements ExtractorInterface {

	private const PAGE_PATTERN = '/contact|تماس|ارتباط|get[\s\-]?in[\s\-]?touch|reach[\s\-]?us/ui';

	/** @var array<string, array<string, mixed>> */
	private $phones = array();
	/** @var array<string, array<string, mixed>> */
	private $emails = array();
	/** @var array<string, array<string, mixed>> */
	private $addresses = array();
	/** @var array<string, array<string, mixed>> */
	private $hours = array();
	/** @var array<string, array<string, mixed>> */
	private $socials = array();
	/** @var array<string, array<string, mixed>> */
	private $maps = array();
	/** @var array<string, mixed> */
	private $business = array();
	/** @var string[] */
	private $warnings = array();
	/** @var string[] */
	private $probed = array();

	public function id(): string {
		return 'contact';
	}

	public function default_limit(): int {
		return 50;
	}

	public function extract( int $offset, int $limit ): array {
		global $wpdb;

		$pages = $wpdb->get_results( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$items = array();
		$urls  = array( home_url( '/' ) );

		foreach ( (array) $pages as $row ) {
			if ( 1 !== preg_match( self::PAGE_PATTERN, $row->post_title . ' ' . urldecode( $row->post_name ) ) ) {
				continue;
			}
			$post = get_post( (int) $row->ID );
			if ( ! $post ) {
				continue;
			}
			try {
				$items[] = $this->page( $post );
				if ( count( $urls ) < 3 ) {
					$urls[] = (string) get_permalink( $post );
				}
			} catch ( \Throwable $e ) {
				/* translators: 1: post ID, 2: error message */
				$this->warnings[] = sprintf( __( 'خطا در خواندن مورد %1$d: %2$s', 'persian-ai-assistant' ), (int) $row->ID, $e->getMessage() );
			}
		}

		if ( apply_filters( 'paia_probe_frontend', true ) ) {
			foreach ( $urls as $url ) {
				$this->probe( $url );
			}
		}
		$this->woocommerce();

		$details = array(
			'business'  => Normalizer::prune( $this->business ),
			'phones'    => array_values( $this->phones ),
			'emails'    => array_values( $this->emails ),
			'addresses' => array_values( $this->addresses ),
			'hours'     => array_values( $this->hours ),
			'socials'   => array_values( $this->socials ),
			'maps'      => array_values( $this->maps ),
		);

		return array(
			'items'       => $items,
			'total'       => count( $items ),
			'offset'      => 0,
			'limit'       => max( 1, count( $items ) ),
			'next_offset' => null,
			'done'        => true,
			'warnings'    => $this->warnings,
			'info'        => array(
				__( 'برگه‌هایی که عنوان یا نامکشان شامل «تماس / ارتباط / Contact» است خوانده می‌شود.', 'persian-ai-assistant' ),
				/* translators: %s: list of URLs */
				sprintf( __( 'برای پیدا کردن اطلاعات هدر و فوتر (که در هیچ برگه‌ای ذخیره نیست) این صفحه‌های عمومی سایت هم واکشی می‌شود: %s', 'persian-ai-assistant' ), $this->probed ? implode( '، ', $this->probed ) : '—' ),
				__( 'از صفحهٔ عمومی: داده‌های schema.org (Organization / LocalBusiness / ContactPoint)، پیوندهای tel: و mailto:، شبکه‌های اجتماعی، نقشه و برچسب‌های «آدرس / ساعت کاری». شماره‌ها به ارقام لاتین نرمال می‌شوند.', 'persian-ai-assistant' ),
				__( 'در صورت فعال بودن ووکامرس، نشانی فروشگاه از تنظیمات آن خوانده می‌شود.', 'persian-ai-assistant' ),
			),
			'details'     => $details,
			'extra_stats' => array(
				'phones'    => count( $this->phones ),
				'emails'    => count( $this->emails ),
				'addresses' => count( $this->addresses ),
				'hours'     => count( $this->hours ),
				'socials'   => count( $this->socials ),
				'maps'      => count( $this->maps ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function page( \WP_Post $post ): array {
		$content = Content::resolve( $post );
		$label   = 'page:' . Text::collapse( wp_strip_all_tags( $post->post_title ) );
		$urls    = array();
		foreach ( $content['links'] as $link ) {
			$urls[] = $link['url'];
		}

		foreach ( $content['links'] as $link ) {
			if ( 'tel' === $link['scope'] ) {
				$this->add_phones( ContactFinder::phones( substr( rawurldecode( $link['url'] ), 4 ) ), $label );
			} elseif ( 'mailto' === $link['scope'] ) {
				$this->add_emails( ContactFinder::emails( substr( $link['url'], 7 ) ), $label );
			}
		}
		$this->add_phones( ContactFinder::phones( $content['text'] ), $label );
		$this->add_emails( ContactFinder::emails( $content['text'] ), $label );
		$this->add_values( $this->addresses, ContactFinder::addresses( $content['text'] ), $label );
		$this->add_values( $this->hours, ContactFinder::hours( $content['text'] ), $label );
		$this->add_urls( $this->socials, ContactFinder::socials( $urls ), $label );
		$this->add_maps( array_merge( $content['maps'], ContactFinder::maps( $urls ) ), $label );

		return Normalizer::prune(
			array(
				'id'         => (int) $post->ID,
				'type'       => $post->post_type,
				'title'      => Text::collapse( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ) ),
				'url'        => (string) get_permalink( $post ),
				'content'    => $content['text'],
				'extraction' => array(
					'content_method' => $content['method'],
					'alternatives'   => $content['candidates'],
					'via'            => array( 'wp_query', 'content:' . $content['method'], 'contact_finder' ),
				),
				'stats'      => array(
					'words'         => Text::words( $content['text'] ),
					'empty_content' => '' === $content['text'] ? 1 : 0,
				),
			)
		);
	}

	private function probe( string $url ): void {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 3,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'user-agent'          => 'PAIA-Extractor/' . PAIA_VERSION,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			/* translators: 1: URL, 2: reason */
			$this->warnings[] = sprintf(
				__( 'واکشی صفحهٔ %1$s انجام نشد (%2$s).', 'persian-ai-assistant' ),
				$url,
				is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response )
			);
			return;
		}

		$this->probed[] = $url;
		$html           = (string) wp_remote_retrieve_body( $response );
		$label          = 'frontend:' . $url;

		foreach ( JsonLd::parse( $html ) as $node ) {
			$this->from_jsonld( $node, $label . '#schema.org' );
		}

		$doc = Html::load( $html );
		if ( ! $doc ) {
			return;
		}
		$xp   = new \DOMXPath( $doc );
		$urls = array();
		foreach ( $xp->query( '//a[@href]' ) ?: array() as $a ) {
			$href   = trim( $a->getAttribute( 'href' ) );
			$urls[] = $href;
			if ( 0 === stripos( $href, 'tel:' ) ) {
				$this->add_phones( ContactFinder::phones( rawurldecode( substr( $href, 4 ) ) ), $label );
			} elseif ( 0 === stripos( $href, 'mailto:' ) ) {
				$this->add_emails( ContactFinder::emails( preg_replace( '/\?.*$/', '', substr( $href, 7 ) ) ), $label );
			}
		}
		$this->add_urls( $this->socials, ContactFinder::socials( $urls ), $label );
		$this->add_maps( ContactFinder::maps( $urls ), $label );
		foreach ( $xp->query( '//iframe[@src]' ) ?: array() as $frame ) {
			$this->add_maps( ContactFinder::maps( array( $frame->getAttribute( 'src' ) ) ), $label );
		}

		// متن فوتر و تگ <address>
		$areas = $xp->query( '//footer|//address|//*[@id="footer"]|//*[contains(concat(" ", normalize-space(@class), " "), " footer ")]|//*[contains(concat(" ", normalize-space(@class), " "), " site-footer ")]' );
		foreach ( $areas ?: array() as $area ) {
			$text = Html::node_text( $area );
			$this->add_phones( ContactFinder::phones( $text ), $label . '#footer' );
			$this->add_emails( ContactFinder::emails( $text ), $label . '#footer' );
			$this->add_values( $this->addresses, ContactFinder::addresses( $text ), $label . '#footer' );
			$this->add_values( $this->hours, ContactFinder::hours( $text ), $label . '#footer' );
			if ( 'address' === strtolower( $area->nodeName ) && '' !== $text ) {
				$this->add_values( $this->addresses, array( Text::cut( Text::collapse( $text ), 300 ) ), $label . '#address-tag' );
			}
		}
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private function from_jsonld( array $node, string $label ): void {
		$types   = implode( ' ', JsonLd::types( $node ) );
		$is_org  = 1 === preg_match( '/Organization|Business|Store|Restaurant|Corporation|Service|Shop|Clinic|School|Hotel|Person/i', $types );
		$is_cp   = false !== stripos( $types, 'ContactPoint' );
		if ( ! $is_org && ! $is_cp ) {
			return;
		}

		if ( $is_org ) {
			foreach ( array( 'name', 'url', 'description' ) as $field ) {
				if ( empty( $this->business[ $field ] ) && ! empty( $node[ $field ] ) && is_string( $node[ $field ] ) ) {
					$this->business[ $field ] = Text::cut( Text::collapse( $node[ $field ] ), 400 );
				}
			}
			if ( empty( $this->business['type'] ) && '' !== $types ) {
				$this->business['type'] = $types;
			}
			if ( ! empty( $node['geo']['latitude'] ) && empty( $this->business['geo'] ) ) {
				$this->business['geo'] = $node['geo']['latitude'] . ',' . ( $node['geo']['longitude'] ?? '' );
			}
			if ( ! empty( $node['sameAs'] ) ) {
				$this->add_urls( $this->socials, ContactFinder::socials( (array) $node['sameAs'] ), $label );
			}
			if ( ! empty( $node['address'] ) ) {
				$address = $this->address_text( $node['address'] );
				if ( '' !== $address ) {
					$this->add_values( $this->addresses, array( $address ), $label );
				}
			}
			foreach ( (array) ( $node['openingHours'] ?? array() ) as $h ) {
				if ( is_string( $h ) ) {
					$this->add_values( $this->hours, array( $h ), $label );
				}
			}
			foreach ( (array) ( $node['openingHoursSpecification'] ?? array() ) as $spec ) {
				if ( is_array( $spec ) && ! empty( $spec['opens'] ) ) {
					$days = implode( '، ', array_map( 'strval', (array) ( $spec['dayOfWeek'] ?? array() ) ) );
					$this->add_values( $this->hours, array( trim( $days . ' ' . $spec['opens'] . '–' . ( $spec['closes'] ?? '' ) ) ), $label );
				}
			}
		}

		$points = $is_cp ? array( $node ) : array();
		foreach ( (array) ( $node['contactPoint'] ?? array() ) as $cp ) {
			if ( is_array( $cp ) ) {
				$points[] = $cp;
			}
		}
		if ( $is_org ) {
			$points[] = $node;
		}
		foreach ( $points as $cp ) {
			foreach ( (array) ( $cp['telephone'] ?? array() ) as $phone ) {
				$this->add_phones( ContactFinder::phones( (string) $phone ), $label );
			}
			foreach ( (array) ( $cp['email'] ?? array() ) as $email ) {
				$this->add_emails( ContactFinder::emails( (string) $email ), $label );
			}
		}
	}

	/**
	 * @param mixed $address
	 */
	private function address_text( $address ): string {
		if ( is_string( $address ) ) {
			return Text::collapse( $address );
		}
		if ( ! is_array( $address ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) as $key ) {
			if ( ! empty( $address[ $key ] ) && is_string( $address[ $key ] ) ) {
				$parts[] = $address[ $key ];
			}
		}

		return Text::collapse( implode( '، ', $parts ) );
	}

	private function woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$parts = array();
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_default_country' ) as $option ) {
			$value = trim( (string) get_option( $option ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
		if ( $parts ) {
			$this->add_values( $this->addresses, array( implode( '، ', $parts ) ), 'woocommerce_settings' );
		}
	}

	/**
	 * @param array<int, array{value:string, raw:string, type:string}> $phones
	 */
	private function add_phones( array $phones, string $source ): void {
		foreach ( $phones as $phone ) {
			$key = $phone['value'];
			if ( ! isset( $this->phones[ $key ] ) ) {
				$this->phones[ $key ] = $phone + array( 'sources' => array() );
			}
			$this->phones[ $key ]['sources'][ $source ] = $source;
		}
	}

	/**
	 * @param string[] $emails
	 */
	private function add_emails( array $emails, string $source ): void {
		foreach ( $emails as $email ) {
			if ( ! isset( $this->emails[ $email ] ) ) {
				$this->emails[ $email ] = array(
					'value'   => $email,
					'sources' => array(),
				);
			}
			$this->emails[ $email ]['sources'][ $source ] = $source;
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $bucket
	 * @param string[]                            $values
	 */
	private function add_values( array &$bucket, array $values, string $source ): void {
		foreach ( $values as $value ) {
			$key = Text::lower( Text::collapse( $value ) );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $bucket[ $key ] ) ) {
				$bucket[ $key ] = array(
					'text'    => $value,
					'sources' => array(),
				);
			}
			$bucket[ $key ]['sources'][ $source ] = $source;
		}
	}

	/**
	 * @param array<string, array<string, mixed>>                $bucket
	 * @param array<int, array{network:string, url:string}> $socials
	 */
	private function add_urls( array &$bucket, array $socials, string $source ): void {
		foreach ( $socials as $social ) {
			$key = rtrim( strtolower( $social['url'] ), '/' );
			if ( ! isset( $bucket[ $key ] ) ) {
				$bucket[ $key ] = $social + array( 'sources' => array() );
			}
			$bucket[ $key ]['sources'][ $source ] = $source;
		}
	}

	/**
	 * @param string[] $urls
	 */
	private function add_maps( array $urls, string $source ): void {
		foreach ( $urls as $url ) {
			if ( ! isset( $this->maps[ $url ] ) ) {
				$this->maps[ $url ] = array(
					'url'     => $url,
					'sources' => array(),
				);
			}
			$this->maps[ $url ]['sources'][ $source ] = $source;
		}
	}
}
