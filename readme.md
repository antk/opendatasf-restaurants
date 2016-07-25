This script downloads and merges the SF OpenData restaurant csv files at [https://data.sfgov.org/Health-and-Social-Services/Restaurant-Scores/stya-26eb](https://data.sfgov.org/Health-and-Social-Services/Restaurant-Scores/stya-26eb) and queries the yelp api for each restaurant by phone number in order to get rating, review, and image data.

####Usage

To just merge the data (without hitting the yelp api for rating, review, and image data)

```$ php data.php```

To include yelp api data, simply

```$ php data.php yelp```

Note that you will need to provide your own yelp api authentication details.  Get a dev account here: [https://www.yelp.com/developers](https://www.yelp.com/developers)