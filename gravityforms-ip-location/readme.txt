=== Gravity Forms IP Location Add-On ===
Contributors: thrivedigital
Author URI: http://thriveweb.com.au
Plugin URI: https://thriveweb.com.au/the-lab/gravity-forms-ip-location-add-on/
Tags: gravity forms, geolocation, ip location, form validation, location data
Requires at least: 5.0
Tested up to: 6.3
Requires PHP: 7.0
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhances Gravity Forms with IP geolocation capabilities for intelligent form handling based on user location data.

== Description ==

The Gravity Forms IP Location Add-On seamlessly integrates geolocation intelligence into your Gravity Forms. By leveraging the IPStack API, this add-on enables you to create location-aware forms that adapt to your users' geographic locations.

= Key Features =

* **Dynamic Merge Tags**: Insert location data directly into your forms using merge tags like `{user:country}`, `{user:city}`, `{user:region}`, etc.
* **Auto-populate Hidden Fields**: Automatically fill hidden fields with location data for use in form processing or third-party integrations
* **Country-based Form Restrictions**: Limit form submissions to users from specific countries
* **Entry Location Data**: Every submission includes detailed location information in the entry notes
* **Smart Caching System**: Multi-layered caching (memory, object cache, and transients) minimizes API calls and improves form performance

= Common Use Cases =

* Create region-specific promotions or offerings
* Validate shipping addresses against detected location
* Deliver country-specific pricing or content
* Comply with geographic service restrictions
* Track form submission origins for analytics

= Technical Details =

* Integrates with [IPStack](https://ipstack.com/) API (free and paid plans supported)
* Implements efficient multi-level cache to minimize API usage
* Supports location validation through admin-configurable country lists

== Installation ==

1. Upload the `gravityforms-ip-location` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Forms → Settings → IP Location to configure your IPStack API key
4. Start using the location merge tags in your Gravity Forms

== Configuration ==

= API Setup =

1. Create an account at [IPStack](https://ipstack.com/) to obtain your API key
2. Enter your API key in Forms → Settings → IP Location

= Form Configuration =

1. Navigate to your form's settings
2. Select the "IP Location" tab
3. Enable country validation if needed and select allowed countries

= Using Merge Tags =

Insert these merge tags into any field:
* `{user:country}` - User's country
* `{user:city}` - User's city
* `{user:region}` - User's region/state
* `{user:continent}` - User's continent
* `{user:latitude}` - User's latitude
* `{user:longitude}` - User's longitude

= Hidden Field Auto-population =

For hidden fields, simply set the default value to any of the merge tags above to capture location data with form submissions.

== Frequently Asked Questions ==

= Is an API key required? =
Yes, this plugin requires an IPStack API key. They offer both free and paid plans.

= How many API calls does this plugin make? =
For each unique IP address, it makes one API call, then caches the result for 24 hours (successful lookups) or 1 hour (failed lookups).

= How accurate is the location data? =
The accuracy depends on the IPStack service and the user's connection. Country-level detection is generally reliable, while city and region accuracy may vary.

= What happens if location detection fails? =
If the API is unavailable or returns an error, the plugin will allow form submission to continue (fail open) and will log the error in the entry notes.

= Can I restrict forms based on user location? =
Yes, you can specify which countries are allowed to submit each form.

= Does this work with GDPR compliance? =
The plugin only processes IP addresses for the purpose of form functionality. However, you should mention IP geolocation in your privacy policy and form disclosures.

== Changelog ==

= 1.3 =
* Added improved entry notes with combined validation and merge tag information
* Enhanced caching mechanism with LRU implementation
* Added cache management interface in settings

= 1.2 =
* Added support for additional location fields (continent, latitude, longitude)
* Improved error handling and logging

= 1.1 =
* Added country validation feature
* Improved merge tag handling

= 1.0 =
* Initial release with basic location merge tags



