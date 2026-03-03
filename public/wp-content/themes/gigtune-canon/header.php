<?php if (!defined('ABSPATH')) exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f172a">
  <link rel="manifest" href="<?php echo esc_url(home_url('/manifest.webmanifest')); ?>">
  <link rel="apple-touch-icon" href="<?php echo esc_url(trailingslashit(get_template_directory_uri()) . 'assets/img/gigtune-logo-bp.png'); ?>">
  <?php wp_head(); ?>
</head>

<body <?php body_class('min-h-screen bg-slate-950 text-slate-200 font-sans selection:bg-purple-500/30 selection:text-white flex flex-col'); ?>>
