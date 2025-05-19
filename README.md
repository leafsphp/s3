<!-- markdownlint-disable no-inline-html -->
<p align="center">
  <br><br>
  <img src="https://leafphp.netlify.app/assets/img/leaf3-logo.png" height="100"/>
  <h1 align="center">S3 Drop-in extension for Leaf FS</h1>
  <br><br>
</p>

# S3 for Leaf FS

[![Latest Stable Version](https://poser.pugx.org/leafs/s3/v/stable)](https://packagist.org/packages/leafs/s3)
[![Total Downloads](https://poser.pugx.org/leafs/s3/downloads)](https://packagist.org/packages/leafs/s3)
[![License](https://poser.pugx.org/leafs/s3/license)](https://packagist.org/packages/leafs/s3)

A drop-in extension for [Leaf FS](https://github.com/leafs/fs) that allows you to use S3 as a storage driver for Leaf FS.

## Installation

You can easily install Leaf using [Composer](https://getcomposer.org/).

```bash
composer require leafs/s3
```

## Leaf MVC

If you are using Leaf MVC, add your S3 URL to the `.env` file:

```env
S3_URL=https://your-s3-url
```

## Leaf without MVC

If you are using Leaf without MVC, you can set the S3 URL in your code:

```php
storage()->bucket('your-s3-url');
```

## Usage

From there, you can use the s3 bucket as you would with your local storage, only wrapping your storage path in the `withBucket()` method to switch to bucket storage. For example, to upload a file:

```php
storage()->upload('path/to/file.txt', withBucket('path/in/s3'));

// or directly from the request
request()->upload('file', withBucket('path/in/s3'));
```

## 💬 Stay In Touch

- [Twitter](https://twitter.com/leafphp)
- [Join the forum](https://github.com/leafsphp/leaf/discussions/37)
- [Chat on discord](https://discord.com/invite/Pkrm9NJPE3)

## 📓 Learning Leaf 3

- Leaf has a very easy to understand [documentation](https://leafphp.dev) which contains information on all operations in Leaf.
- You can also check out our [youtube channel](https://www.youtube.com/channel/UCllE-GsYy10RkxBUK0HIffw) which has video tutorials on different topics
- You can also learn from [codelabs](https://codelabs.leafphp.dev) and contribute as well.

## 😇 Contributing

We are glad to have you. All contributions are welcome! To get started, familiarize yourself with our [contribution guide](https://leafphp.dev/community/contributing.html) and you'll be ready to make your first pull request 🚀.

To report a security vulnerability, you can reach out to [@mychidarko](https://twitter.com/mychidarko) or [@leafphp](https://twitter.com/leafphp) on twitter. We will coordinate the fix and eventually commit the solution in this project.

## 🤩 Sponsoring Leaf

Your cash contributions go a long way to help us make Leaf even better for you. You can sponsor Leaf and any of our packages on [open collective](https://opencollective.com/leaf) or check the [contribution page](https://leafphp.dev/support/) for a list of ways to contribute.

And to all our [existing cash/code contributors](https://leafphp.dev#sponsors), we love you all ❤️
