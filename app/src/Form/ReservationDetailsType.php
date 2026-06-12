<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Reservation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Reservation>
 */
class ReservationDetailsType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('guestName', TextType::class, [
                'label' => 'Jméno hosta',
                'required' => true,
            ])
            ->add('guestEmail', EmailType::class, [
                'label' => 'E-mail hosta (kam poslat fakturu)',
                'required' => false,
            ])
            ->add('guestPhone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
            ])
            ->add('guestStreet', TextType::class, [
                'label' => 'Ulice a č. p.',
                'required' => false,
            ])
            ->add('guestZip', TextType::class, [
                'label' => 'PSČ',
                'required' => false,
            ])
            ->add('guestCity', TextType::class, [
                'label' => 'Město',
                'required' => false,
            ])
            ->add('guestCountry', TextType::class, [
                'label' => 'Země (ISO kód, např. CZ, DE, SK)',
                'required' => false,
                'attr' => ['maxlength' => 2, 'placeholder' => 'CZ'],
            ])
            ->add('guestCompanyName', TextType::class, [
                'label' => 'Firma (volitelné)',
                'required' => false,
            ])
            ->add('guestIco', TextType::class, [
                'label' => 'IČO',
                'required' => false,
            ])
            ->add('guestDic', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
            ])
            ->add('guestsAdult', IntegerType::class, [
                'label' => 'Dospělých',
                'required' => true,
                'attr' => ['min' => 0],
            ])
            ->add('guestsChild', IntegerType::class, [
                'label' => 'Dětí',
                'required' => true,
                'attr' => ['min' => 0],
            ])
            ->add('hasPet', CheckboxType::class, [
                'label' => 'Host se psem',
                'required' => false,
            ])
            ->add('petsNote', TextType::class, [
                'label' => 'Plemeno / poznámka ke psovi',
                'required' => false,
            ])
            ->add('needsBabyCot', CheckboxType::class, [
                'label' => 'Připravit dětskou postýlku',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Poznámka',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('acquisitionSource', TextType::class, [
                'label' => 'Odkud nás zná (Booking, Airbnb, Web, Facebook, E-chalupy, Návrat, …)',
                'required' => false,
                'attr' => [
                    'list' => 'acquisition-sources',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
