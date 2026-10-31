import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { login, openSettingsTab, SCREEN, SCREEN_SETTINGS, wp } from "./wp";

/**
 * Ajustes → General «Categories left out» y Ajustes → Sugerencias «Advanced» (F1-16): marcar categorías con el
 * teclado, guardar, ver que sus entradas salen del análisis, y los ajustes avanzados plegados con sus límites.
 */

const AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

/** Tres entradas parecidas; una en la categoría «Privada cat». */
function seedCategories(): number {
	const out = wp(
		"eval",
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'ca-%' OR post_name LIKE 'pf-%' OR post_name LIKE 'ex-%' OR post_name LIKE 'e2e-%' OR post_name LIKE 'entrada-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs', 'changes', 'dismissed' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$block = static fn( $t ) => "<!-- wp:paragraph -->\\n<p>$t</p>\\n<!-- /wp:paragraph -->";
		$make = static fn( $slug, $title, $content ) => wp_insert_post( array( 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish' ) );
		$cat = wp_insert_term( 'Privada cat', 'category' );
		$cat = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
		$make( 'ca-bomba', 'Bomba de calor: cuánto se ahorra', $block( 'La bomba de calor aerotérmica extrae energía del aire exterior. Una bomba de calor bien dimensionada ahorra mucho en calefacción.' ) );
		$make( 'ca-casa', 'Aislamiento de casas', $block( 'Con una bomba de calor y un buen aislamiento, el consumo de energía baja mucho en cualquier casa moderna.' ) );
		$private = $make( 'ca-privada', 'Guía de calefacción para casas', $block( 'La instalación de una bomba de calor reduce el consumo de energía en las casas con buen aislamiento.' ) );
		wp_set_post_categories( $private, array( $cat ) );
		echo $private;
		`,
	);
	return Number(out.trim().split("\n").pop());
}

const axe = (page: Page, selector: string) =>
	new AxeBuilder({ page }).include(selector).withTags(AXE_TAGS).analyze();

/** Termina el análisis que lanzó guardar los ajustes. */
function finishJob(): void {
	wp("magic-linking", "index");
}

function indexed(id: number): boolean {
	return (
		wp(
			"eval",
			`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}magiclinking_docs WHERE post_id = ${id}" );`,
		).trim() === "1"
	);
}

test.describe("Settings: categories left out and advanced", () => {
	let privateId = 0;

	test.beforeEach(() => {
		privateId = seedCategories();
		wp("magic-linking", "index");
	});

	test.afterAll(() => {
		wp(
			"eval",
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'ca-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			`,
		);
	});

	test("a category is ticked with the keyboard, saved, and its entries leave the analysis", async ({
		page,
	}) => {
		await login(page);
		await page.goto(`${SCREEN_SETTINGS}#tab=general`);
		const card = page.locator("#magiclinking-s-excluded-categories");
		await expect(
			card.getByRole("heading", { name: "Categories left out" }),
		).toBeVisible();
		await expect(card.getByText("Privada cat")).toBeVisible();
		expect(indexed(privateId)).toBe(true);

		const box = card.getByRole("checkbox", { name: "Privada cat" });
		await box.focus();
		await page.keyboard.press("Space");
		await expect(box).toBeChecked();
		await expect(
			page.getByRole("tab", { name: "General (unsaved changes)" }),
		).toBeVisible();
		expect((await axe(page, ".magiclinking-wrap")).violations).toEqual([]);

		await page.getByRole("button", { name: "Save settings" }).click();
		await expect(
			page
				.locator("#magiclinking-root")
				.getByText(/Settings saved\. What Magic Linking reads has changed/),
		).toBeVisible();
		finishJob();
		expect(indexed(privateId)).toBe(false);

		// Al recargar sigue marcada, y quitarla trae la entrada de vuelta.
		await page.reload();
		await expect(
			page
				.locator("#magiclinking-s-excluded-categories")
				.getByRole("checkbox", { name: "Privada cat" }),
		).toBeChecked();
		await page
			.locator("#magiclinking-s-excluded-categories")
			.getByRole("checkbox", { name: "Privada cat" })
			.uncheck();
		await page.getByRole("button", { name: "Save settings" }).click();
		await expect(
			page.locator("#magiclinking-root").getByText(/Settings saved\./),
		).toBeVisible();
		finishJob();
		expect(indexed(privateId)).toBe(true);
	});

	test("advanced settings are folded, keep the engine defaults and are bounded", async ({
		page,
	}) => {
		await login(page);
		await page.goto(`${SCREEN_SETTINGS}#tab=suggestions`);
		const card = page.locator("#magiclinking-s-advanced");
		await expect(card.getByRole("heading", { name: "Advanced" })).toBeVisible();
		const summary = card.getByText("Show advanced settings");
		await expect(card.getByLabel("Key terms per entry")).toBeHidden();

		await summary.focus();
		await page.keyboard.press("Enter");
		const k = card.getByLabel("Key terms per entry");
		const threshold = card.getByLabel("Score threshold (%)");
		await expect(k).toHaveValue("40");
		await expect(threshold).toHaveValue("35");
		await expect(
			page.getByRole("button", { name: "Save settings" }),
		).toBeDisabled();
		expect((await axe(page, ".magiclinking-wrap")).violations).toEqual([]);

		// Fuera de rango: el aviso se ve junto al campo y se corrige al salir.
		await k.fill("90");
		await expect(k).toHaveAttribute("aria-invalid", "true");
		await expect(
			page.getByText(/Enter a whole number from 20 to 80/),
		).toBeVisible();
		expect((await axe(page, ".magiclinking-wrap")).violations).toEqual([]);
		await k.blur();
		await expect(k).toHaveValue("80");

		await k.fill("30");
		await threshold.fill("50");
		await page.getByRole("button", { name: "Save settings" }).click();
		await expect(
			page
				.locator("#magiclinking-root")
				.getByText(/Settings saved\. What Magic Linking reads has changed/),
		).toBeVisible();
		finishJob();

		const saved = JSON.parse(
			wp("option", "get", "magiclinking_settings", "--format=json"),
		) as { k_terms: number; score_threshold: number };
		expect(saved.k_terms).toBe(30);
		expect(saved.score_threshold).toBe(50);

		// Solo el umbral: se guarda sin volver a analizar.
		await page.reload();
		await openSettingsTab(page, "Suggestions");
		await page
			.locator("#magiclinking-s-advanced")
			.getByText("Show advanced settings")
			.click();
		await page.getByLabel("Score threshold (%)").fill("45");
		await page.getByRole("button", { name: "Save settings" }).click();
		await expect(
			page
				.locator("#magiclinking-root")
				.getByText("Settings saved.", { exact: true }),
		).toBeVisible();
	});

	test("with many categories a filter narrows the list and keeps the ticked ones", async ({
		page,
	}) => {
		wp(
			"eval",
			`for ( $i = 1; $i <= 60; $i++ ) { if ( ! term_exists( "Masiva $i", 'category' ) ) { wp_insert_term( "Masiva $i", 'category' ); } }`,
		);
		await login(page);
		await page.goto(`${SCREEN_SETTINGS}#tab=general`);
		const card = page.locator("#magiclinking-s-excluded-categories");
		const filter = card.getByLabel("Filter Categories by name");
		await expect(filter).toBeVisible();
		await card.getByRole("checkbox", { name: "Privada cat" }).check();
		await filter.fill("Masiva 5");
		await expect(
			card.getByRole("checkbox", { name: "Masiva 5", exact: true }),
		).toBeVisible();
		await expect(
			card.getByRole("checkbox", { name: "Masiva 6", exact: true }),
		).toHaveCount(0);
		await expect(
			card.getByRole("checkbox", { name: "Privada cat" }),
		).toBeChecked();
		expect((await axe(page, ".magiclinking-wrap")).violations).toEqual([]);
		wp(
			"eval",
			`foreach ( get_terms( array( 'taxonomy' => 'category', 'name__like' => 'Masiva', 'hide_empty' => false ) ) as $t ) { wp_delete_term( $t->term_id, 'category' ); }`,
		);
	});

	test("the report lists entries left out by category with their reason and a link to the settings", async ({
		page,
	}) => {
		wp(
			"eval",
			`$t = get_term_by( 'name', 'Privada cat', 'category' ); $s = new MagicLinking\\Core\\Settings(); $s->update( array( 'excluded_categories' => array( (int) $t->term_taxonomy_id ) ) );`,
		);
		finishJob();
		await login(page);
		await page.goto(SCREEN);
		await page
			.locator(".subsubsub")
			.getByRole("link", { name: /Excluded/ })
			.click();
		const table = page.getByRole("table", { name: "Internal links by entry" });
		const row = table.locator("tbody tr", {
			hasText: "Guía de calefacción para casas",
		});
		await expect(row).toContainText("category excluded");
		const link = row.getByRole("link", {
			name: /Change excluded categories/,
		});
		await expect(link).toHaveAttribute(
			"href",
			/magic-linking-settings.*#tab=general/,
		);
		await expect(
			row.getByRole("button", { name: /Include in the analysis/ }),
		).toHaveCount(0);
		expect((await axe(page, ".magiclinking-wrap")).violations).toEqual([]);
	});
});
