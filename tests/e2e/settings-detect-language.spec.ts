import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { login, SCREEN_SETTINGS, wp } from "./wp";

/**
 * Ajustes → General «Detect the language of each entry from its text» (F1-37): desactivada por defecto, se guarda,
 * lanza un análisis nuevo y una entrada en español de un sitio en inglés queda marcada como `es`.
 */

const AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

function seed(): number {
	const out = wp(
		"eval",
		`
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'dl-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
		delete_option( 'magiclinking_settings' );
		update_option( 'WPLANG', '' );
		update_user_meta( 1, $wpdb->get_blog_prefix() . 'magiclinking_wizard_dismissed', '1' );
		$id = wp_insert_post( array( 'post_name' => 'dl-bomba', 'post_title' => 'Bomba de calor', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>La bomba de calor aerotérmica extrae la energía del aire exterior y la transforma en calefacción para toda la casa. Con un buen aislamiento, el consumo baja mucho y las facturas de la luz son más pequeñas durante el invierno, que es cuando más se nota.</p><!-- /wp:paragraph -->' ) );
		echo $id;
		`,
	);
	return Number(out.trim().split("\n").pop());
}

function docLang(id: number): string {
	return wp(
		"eval",
		`global $wpdb; echo (string) $wpdb->get_var( "SELECT lang FROM {$wpdb->prefix}magiclinking_docs WHERE post_id = ${id}" );`,
	).trim();
}

test.describe("Settings: detect the language from the text", () => {
	let postId = 0;

	test.beforeEach(() => {
		postId = seed();
		wp("magic-linking", "index");
	});

	test.afterAll(() => {
		wp(
			"eval",
			`
			global $wpdb;
			foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'dl-%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
			foreach ( array( 'docs', 'links', 'jobs' ) as $t ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}magiclinking_$t" ); }
			delete_option( 'magiclinking_settings' );
			`,
		);
	});

	test("it is off by default, is saved and marks a Spanish entry as es", async ({
		page,
	}) => {
		expect(docLang(postId)).toBe("en");

		await login(page);
		await page.goto(`${SCREEN_SETTINGS}#tab=general`);
		const box = page.getByRole("checkbox", {
			name: "Detect the language of each entry from its text",
		});
		await expect(box).toBeVisible();
		await expect(box).not.toBeChecked();
		await expect(box).toBeEnabled();

		await box.focus();
		await page.keyboard.press("Space");
		await expect(box).toBeChecked();
		const axe = await new AxeBuilder({ page })
			.include(".magiclinking-wrap")
			.withTags(AXE_TAGS)
			.analyze();
		expect(axe.violations).toEqual([]);

		await page.getByRole("button", { name: "Save settings" }).click();
		await expect(
			page
				.locator("#magiclinking-root")
				.getByText(/Settings saved\. What Magic Linking reads has changed/),
		).toBeVisible();
		wp("magic-linking", "index");
		expect(docLang(postId)).toBe("es");

		await page.reload();
		await expect(
			page.getByRole("checkbox", {
				name: "Detect the language of each entry from its text",
			}),
		).toBeChecked();
	});
});
