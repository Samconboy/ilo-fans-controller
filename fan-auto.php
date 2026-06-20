<?php
require 'config.inc.php';

$MONITORED = ['01-Inlet Ambient', '02-CPU 1', '03-CPU 2', '08-HD Max', '27-HD Controller'];

function ilo_curl($path) {
	global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD;
	$ch = curl_init("https://$ILO_HOST$path");
	curl_setopt($ch, CURLOPT_USERPWD, "$ILO_USERNAME:$ILO_PASSWORD");
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	return curl_exec($ch);
}

function get_temperatures() {
	global $MONITORED;
	$raw = ilo_curl('/redfish/v1/chassis/1/Thermal/');
	if (!$raw) return [];
	$temps = [];
	foreach (json_decode($raw, true)['Temperatures'] as $s) {
		$name = $s['Name'];
		if (in_array($name, $MONITORED) && $s['Status']['State'] === 'Enabled')
			$temps[explode('-', $name, 2)[1]] = $s['ReadingCelsius'];
	}
	return $temps;
}

function get_fan_curve() {
	$default = [[0, 10], [40, 10], [55, 20], [65, 45], [75, 75], [85, 100]];
	if (!file_exists('fan_curve.json')) return $default;
	return json_decode(file_get_contents('fan_curve.json'), true) ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	if (isset($_GET['api'])) {
		header('Content-Type: application/json');
		if ($_GET['api'] === 'temps')     die(json_encode(get_temperatures()));
		if ($_GET['api'] === 'fan_curve') die(json_encode(get_fan_curve()));
		die('{}');
	}
	$FAN_CURVE = get_fan_curve();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$data = json_decode(file_get_contents('php://input'), true);
	if (isset($data['action']) && $data['action'] === 'fan_curve' && isset($data['curve'])) {
		$curve = $data['curve'];
		usort($curve, fn($a, $b) => $a[0] <=> $b[0]);
		$raw = json_encode($curve, JSON_PRETTY_PRINT);
		file_put_contents('fan_curve.json', $raw);
		die($raw);
	}
	die('Invalid request.');
}
?>
<!DOCTYPE html>
<html x-data :class="$store.darkMode.active ? 'dark' : ''">
<head>
	<title>iLO Auto Control</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/x-icon" href="./favicon.ico">
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400;1,500;1,700&family=JetBrains+Mono&display=swap" rel="stylesheet">
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
	<style type="text/tailwindcss">
		[x-cloak] { display: none !important; }
		:root.dark { color-scheme: dark; }
		.outline-button {
			@apply outline-none select-none cursor-pointer disabled:cursor-not-allowed transition duration-75 rounded-md border border-emerald-500
			       dark:disabled:border-emerald-500/20 enabled:hover:border-emerald-600 enabled:dark:hover:border-emerald-400
			       text-emerald-500 enabled:hover:text-emerald-600 enabled:dark:hover:text-emerald-400
			       dark:disabled:text-emerald-500/20 disabled:border-emerald-500/40 disabled:text-emerald-500/40;
		}
		input, .input {
			@apply transition-all duration-75 outline-none border rounded-md dark:shadow bg-gray-50 border-gray-175
			       disabled:opacity-50 placeholder-gray-300 dark:text-gray-200 dark:placeholder-gray-750 dark:bg-gray-900
			       dark:focus:border-gray-825 dark:border-gray-875 dark:enabled:hover:border-gray-825 hover:border-gray-275 focus:border-gray-275;
		}
		@custom-variant dark (&:where(.dark, .dark *));
		@theme {
			--font-sans: "DM Sans", sans-serif;
			--font-mono: "JetBrains Mono", monospace;
			--color-gray-975: #0A0A0A; --color-gray-950: #0F0F10; --color-gray-925: #141415; --color-gray-900: #19191A;
			--color-gray-875: #232324; --color-gray-850: #28282A; --color-gray-825: #2D2D2F; --color-gray-800: #323234;
			--color-gray-750: #414144; --color-gray-700: #4B4B4E; --color-gray-650: #5A5A5E; --color-gray-600: #646468;
			--color-gray-550: #737378; --color-gray-500: #7D7D82; --color-gray-450: #8D8D91; --color-gray-400: #97979B;
			--color-gray-350: #A7A7AA; --color-gray-300: #B1B1B4; --color-gray-275: #BBBBBE; --color-gray-250: #C1C1C3;
			--color-gray-200: #CBCBCD; --color-gray-175: #D5D5D7; --color-gray-150: #E0E0E1; --color-gray-100: #E5E5E6;
			--color-gray-75: #EFEFF0;  --color-gray-50: #F5F5F5;  --color-gray-25: #FAFAFA;
		}
	</style>
</head>
<body class="w-full dark:bg-gray-950 transition-colors duration-75">
<main class="p-5 pb-8 sm:px-10 max-w-[40rem] mx-auto">

	<!-- Header -->
	<div class="flex items-center justify-between mb-7">
		<a href="./ilo-fans-controller.php" class="outline-button px-3 py-1.5 text-sm">&larr; Fans</a>
		<h1 class="font-bold text-2xl sm:text-3xl dark:text-white text-black select-none">Auto Control</h1>
		<button
			class="cursor-pointer transition-colors duration-75 p-2 sm:p-1.5 leading-none rounded-full dark:bg-gray-900 dark:text-gray-600
			       dark:hover:bg-gray-875 dark:hover:text-gray-500 bg-gray-50 text-gray-300 hover:bg-gray-75 hover:text-gray-400"
			@click="$store.darkMode.cycleMode()"
		>
			<template x-if="$store.darkMode.state === 'system'">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
			</template>
			<template x-if="$store.darkMode.state === 'light'">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
			</template>
			<template x-if="$store.darkMode.state === 'dark'">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
			</template>
		</button>
	</div>

	<!-- Temperatures -->
	<div x-data>
		<div class="flex items-center justify-between mb-4">
			<h2 class="text-2xl font-semibold dark:text-white text-black select-none">Temperatures</h2>
			<button @click="$store.temps.refresh()" class="outline-button px-2 py-1 text-xs" :disabled="$store.temps.loading">Refresh</button>
		</div>
		<div class="space-y-3">
			<template x-for="[name, temp] in Object.entries($store.temps.temps)" :key="name">
				<div class="flex items-center justify-between">
					<p class="text-sm dark:text-gray-300 text-gray-600 select-none" x-text="name"></p>
					<span
						class="text-sm font-mono font-medium px-2 py-0.5 rounded"
						:class="temp >= 70 ? 'text-red-500 dark:bg-red-500/10 bg-red-50' : temp >= 55 ? 'text-amber-500 dark:bg-amber-500/10 bg-amber-50' : 'dark:text-emerald-400 text-emerald-600 dark:bg-emerald-500/10 bg-emerald-50'"
						x-text="temp + '°C'"
					></span>
				</div>
			</template>
			<p x-show="Object.keys($store.temps.temps).length === 0" class="text-sm dark:text-gray-750 text-gray-350 italic">Loading...</p>
		</div>
		<p class="text-xs dark:text-gray-750 text-gray-350 mt-3 font-mono select-none" x-show="$store.temps.lastUpdated" x-text="'Updated ' + $store.temps.lastUpdated"></p>
	</div>

	<div class="h-px w-full bg-gray-100 dark:bg-gray-900 my-7"></div>

	<!-- Fan Curve -->
	<div x-data>
		<div class="flex items-center justify-between mb-1">
			<h2 class="text-2xl font-semibold dark:text-white text-black select-none">Fan Curve</h2>
			<button @click="$store.curve.save()" class="outline-button px-3 py-1 text-sm" x-text="$store.curve.justSaved ? 'Saved ✓' : 'Save'"></button>
		</div>
		<p class="text-sm dark:text-gray-550 text-gray-450 mb-5 select-none">
			Sorted by temperature on save. Changes apply within 60s.
		</p>
		<div class="space-y-3">
			<div class="flex text-xs dark:text-gray-750 text-gray-350 select-none px-1 mb-1">
				<span class="w-24 text-center">Temp (&deg;C)</span>
				<span class="flex-1 ml-3">Fan speed</span>
				<span class="w-10 text-right mr-8">%</span>
			</div>
			<template x-for="(point, i) in $store.curve.points" :key="i">
				<div class="flex items-center space-x-3">
					<input type="number" min="0" max="150" class="w-20 px-1.5 py-0.5 font-mono text-sm text-center" x-model.number="point[0]">
					<input
						type="range" min="0" max="100" step="1"
						class="flex-1 h-3.5 !rounded-full border appearance-none [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:bg-emerald-500 [&::-webkit-slider-thumb]:w-5 [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:rounded-full cursor-pointer"
						x-model.number="point[1]"
					>
					<span class="w-10 text-right font-mono text-sm dark:text-gray-300 text-gray-600 select-none" x-text="point[1] + '%'"></span>
					<button
						@click="$store.curve.removePoint(i)"
						:disabled="$store.curve.points.length <= 2"
						class="transition-colors duration-75 text-gray-300 dark:text-gray-750 hover:text-red-500 dark:hover:text-red-500 disabled:opacity-20 disabled:cursor-not-allowed"
					>
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
							<path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
						</svg>
					</button>
				</div>
			</template>
		</div>
		<button
			@click="$store.curve.addPoint()"
			class="mt-4 w-full input cursor-pointer border-dashed !bg-transparent py-1.5 text-sm dark:text-gray-750 text-gray-300 hover:dark:text-gray-600 hover:text-gray-400 transition-colors duration-75"
		>
			+ Add point
		</button>
	</div>

</main>
<script>
	document.addEventListener('alpine:init', () => {
		Alpine.store('darkMode', {
			active: false, state: null,
			updateState() {
				if (!('theme' in localStorage)) {
					this.state = 'system';
					this.active = window.matchMedia('(prefers-color-scheme: dark)').matches;
				} else {
					this.state = localStorage.theme;
					this.active = localStorage.theme === 'dark';
				}
			},
			cycleMode() {
				switch (this.state) {
					case 'system': localStorage.theme = 'light'; this.state = 'light'; break;
					case 'light':  localStorage.theme = 'dark';  this.state = 'dark';  break;
					default: localStorage.removeItem('theme'); this.state = 'system';
				}
				this.updateState();
			},
			init() { this.updateState(); }
		});

		Alpine.store('temps', {
			temps: {}, loading: false, lastUpdated: null,
			async refresh() {
				this.loading = true;
				try {
					const res = await fetch('?api=temps');
					if (res.ok) {
						this.temps = await res.json();
						this.lastUpdated = new Date().toLocaleTimeString();
					}
				} finally { this.loading = false; }
			},
			init() {
				this.refresh();
				setInterval(() => this.refresh(), 30000);
			}
		});

		Alpine.store('curve', {
			points: <?php echo json_encode($FAN_CURVE); ?>,
			justSaved: false,
			async save() {
				const res = await fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
					method: 'POST',
					body: JSON.stringify({ action: 'fan_curve', curve: this.points }),
				});
				if (res.ok) {
					this.points = await res.json();
					this.justSaved = true;
					setTimeout(() => { this.justSaved = false; }, 2000);
				}
			},
			addPoint() {
				const last = this.points[this.points.length - 1];
				this.points.push([Math.min((last?.[0] ?? 40) + 10, 150), Math.min((last?.[1] ?? 10) + 10, 100)]);
			},
			removePoint(i) {
				if (this.points.length > 2) this.points.splice(i, 1);
			}
		});
	});
</script>
</body>
</html>
