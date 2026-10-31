import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { login, openSettingsTab, SCREEN_SETTINGS, wp } from "./wp";

/**
 * Contenido pilar y filtros Ignorar/Considerar/Exigir (F1-25, D-30): la casilla del panel, la tarjeta de Ajustes y el
 * estado vacío que explica el filtro. Todo con axe.
 */

const AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

interface Ids {
	origen: number;
	yoast: number;
	rank: number;
}

/** Entradas de prueba: un origen en su propia categoría y otras tres en «Sin categoría». */
function seedPillar(): Ids {
	const out = wp(
		"eval",
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pf-%' OR post_name LIKE 'pe-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$cat = wp_insert_term( 'Energía pf', 'category' );
		$cat = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
		$make( 'pf-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho en calefacción.' ) );
		$yoast = $make( 'pf-yoast', 'Aislamiento de casas', $block( 'Con una bomba de calor y un buen aislamiento, el consumo de energía baja mucho en cualquier casa moderna.' ) );
		$rank = $make( 'pf-rank', 'Suelo radiante en casas', $block( 'El suelo radiante con una bomba de calor reparte la calefacción de forma uniforme por toda la casa.' ) );
		$origen = $make( 'pf-origen', 'Guía de calefacción para casas', $block( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		wp_set_post_categories( $origen, array( $cat ) );
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR ); }
		file_put_contents( WPMU_PLUGIN_DIR . '/magiclinking-e2e-seo.php', "<?php add_filter( 'magiclinking_seo_plugin_active', '__return_true' );" );
		update_post_meta( $yoast, '_yoast_wpseo_is_cornerstone', '1' );
		update_post_meta( $rank, 'rank_math_pillar_content', 'on' );
		echo wp_json_encode( array( 'origen' => $origen, 'yoast' => $yoast, 'rank' => $rank ) );
		`,
	);
	return JSON.parse(out.trim().split("\n").pop() ?? "{}") as Ids;
}

async function openBlockEditor(page: Page, id: number): Promise<void> {
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await page.waitForFunction(
		() =>
			// @ts-expect-error wp lo pone WordPress.
			window.wp?.data?.select("core/block-editor")?.getBlocks().length > 0,
	);
	await page.evaluate(() => {
		// @ts-expect-error wp lo pone WordPress.
		const prefs = window.wp.data.dispatch("core/preferences");
		prefs.set("core/edit-post", "welcomeGuide", false);
		prefs.set("core/edit-post", "fullscreenMode", false);
	});
	const close = page.getByRole("button", { name: "Close", exact: true });
	if (await close.isVisible().catch(() => false)) {
		await close.click();
	}
}

async function openSidebar(page: Page) {
	await page
		.getByRole("button", { name: /^Internal links/ })
		.first()
		.click();
	const panel = page.locator(".magiclinking-panel");
	await expect(panel).toBeVisible();
	return panel;
}

async function expectAccessible(page: Page, selector: string): Promise<void> {
	await page.waitForFunction(() =>
		document
			.getAnimations()
			.every((animation) => animation.playState !== "running"),
	);
	const results = await new AxeBuilder({ page })
		.include(selector)
		.withTags(AXE_TAGS)
		.analyze();
	expect(results.violations).toEqual([]);
}

test.describe("Pillar content and filters", () => {
	let ids: Ids;

	test.beforeEach(() => {
		ids = seedPillar();
		wp("magic-linking", "index");
	});

	test.afterAll(() => {
		wp(
			"eval",
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'pf-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			@unlink( WPMU_PLUGIN_DIR . '/magiclinking-e2e-seo.php' );
			`,
		);
	});

	test("the panel checkbox saves at once and Yoast or Rank Math marks are shown with a note", async ({
		page,
	}) => {
		await login(page);

		// Sin marca: la casilla está libre y guardar es inmediato.
		await openBlockEditor(page, ids.origen);
		let panel = await openSidebar(page);
		let box = panel.getByRole("checkbox", { name: "Pillar content" });
		await expect(box).toBeEnabled();
		await expect(box).not.toBeChecked();
		await expectAccessible(page, ".magiclinking-panel");
		await box.check();
		await expect(box).toBeChecked();
		await expect
			.poll(() =>
				wp(
					"post",
					"meta",
					"get",
					String(ids.origen),
					"_magiclinking_pillar",
				).trim(),
			)
			.toBe("1");

		// Al recargar sigue marcada.
		await openBlockEditor(page, ids.origen);
		panel = await openSidebar(page);
		box = panel.getByRole("checkbox", { name: "Pillar content" });
		await expect(box).toBeChecked();
		await box.uncheck();
		await expect
			.poll(() =>
				wp(
					"post",
					"meta",
					"list",
					String(ids.origen),
					"--keys=_magiclinking_pillar",
					"--format=count",
				).trim(),
			)
			.toBe("0");

		// Yoast SEO la marca: aparece marcada, con la nota, y no se cambia aquí.
		await openBlockEditor(page, ids.yoast);
		panel = await openSidebar(page);
		box = panel.getByRole("checkbox", { name: "Pillar content" });
		await expect(box).not.toBeChecked();
		await expect(box).toBeEnabled();
		await expect(panel).toContainText(
			"Also marked as cornerstone content in Yoast SEO. Change that mark there.",
		);
		await expect(panel).toContainText(
			"Counts as pillar content because of that mark",
		);
		await expectAccessible(page, ".magiclinking-panel");

		// La propia se marca y se quita aunque Yoast la marque; la nota sigue.
		await box.check();
		await expect(box).toBeChecked();
		await expect
			.poll(() =>
				wp(
					"post",
					"meta",
					"get",
					String(ids.yoast),
					"_magiclinking_pillar",
				).trim(),
			)
			.toBe("1");
		await expect(panel).toContainText("Counts as pillar content.");
		await box.uncheck();
		await expect(box).not.toBeChecked();
		await expect(panel).toContainText("whatever this box says");

		// Rank Math, igual.
		await openBlockEditor(page, ids.rank);
		panel = await openSidebar(page);
		box = panel.getByRole("checkbox", { name: "Pillar content" });
		await expect(box).not.toBeChecked();
		await expect(box).toBeEnabled();
		await expect(panel).toContainText(
			"Also marked as pillar content in Rank Math. Change that mark there.",
		);
	});

	test("the Suggestions tab offers Ignore, Take into account and Require per filter, as quality settings", async ({
		page,
	}) => {
		await login(page);
		await page.goto(SCREEN_SETTINGS);
		const root = page.locator("#magiclinking-root");
		await openSettingsTab(page, "Suggestions");

		const card = root.locator("#magiclinking-s-filters");
		await expect(
			card.getByRole("heading", {
				level: 2,
				name: "Which entries are suggested",
			}),
		).toBeVisible();
		await expect(card).toContainText("Quality settings, not limits");
		const category = card.getByLabel("Category", { exact: true });
		await expect(category).toHaveValue("ignore");
		await expect(card.getByLabel("Content type")).toHaveValue("ignore");
		await expect(card.getByLabel("Tag", { exact: true })).toHaveValue("ignore");
		await expectAccessible(page, "#magiclinking-s-filters");

		await category.selectOption("require");
		await card.getByLabel("Tag", { exact: true }).selectOption("consider");
		await root.getByRole("button", { name: "Save settings" }).click();
		await expect(root.getByText("Settings saved.")).toBeVisible();

		const saved = JSON.parse(
			wp("option", "get", "magiclinking_settings", "--format=json"),
		) as { filters: Record<string, string> };
		expect(saved.filters).toEqual({
			type: "ignore",
			category: "require",
			tag: "consider",
		});

		await page.reload();
		await openSettingsTab(page, "Suggestions");
		await expect(
			root
				.locator("#magiclinking-s-filters")
				.getByLabel("Category", { exact: true }),
		).toHaveValue("require");
	});

	test("with Require category the empty state explains the filter and links to the settings", async ({
		page,
	}) => {
		await login(page);

		// Sin filtro, las tres entradas de otra categoría se proponen.
		await openBlockEditor(page, ids.origen);
		let panel = await openSidebar(page);
		await expect(panel.locator(".magiclinking-card").first()).toBeVisible();

		wp(
			"option",
			"update",
			"magiclinking_settings",
			'{"filters":{"category":"require"}}',
			"--format=json",
		);
		await openBlockEditor(page, ids.origen);
		panel = await openSidebar(page);
		await expect(panel.locator(".magiclinking-card")).toHaveCount(0);
		await expect(panel).toContainText(
			"only entries that match this one in category are searched",
		);
		const link = panel.getByRole("link", { name: "Settings → Suggestions" });
		await expect(link).toHaveAttribute(
			"href",
			/magic-linking-settings.*#tab=suggestions/,
		);
		await expectAccessible(page, ".magiclinking-panel");

		// Entrantes, igual.
		await panel.getByRole("tab", { name: /^Inbound/ }).click();
		await expect(panel).toContainText(
			"only entries that match this one in category are searched",
		);
	});
});
