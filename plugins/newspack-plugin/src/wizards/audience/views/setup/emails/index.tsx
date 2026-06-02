/**
 * Newspack > Settings > Emails
 */

/**
 * Internal dependencies.
 */
import WizardsTab from '../../../../wizards-tab';
import { default as EmailsSection } from './emails';
import WizardSection from '../../../../wizards-section';

function Emails() {
	return (
		<WizardsTab className="newspack-emails-tab">
			<WizardSection>
				<EmailsSection />
			</WizardSection>
		</WizardsTab>
	);
}

export default Emails;
