<?php
/*
Plugin Name: Formidable AWeber Custom
Description: Enhance your Formidable-AWeber integration to support multiple forms with unique tags on a single AWeber list. This plugin allows you to add different tags to subscribers based on which form they submit, eliminating the need for multiple AWeber lists and reducing costs. Perfect for tracking different lead sources, content downloads, or event registrations without paying for additional AWeber lists.
Version: 1.0.0
Author: Dean Davis  
*/

// Prevent direct access
if (!defined('ABSPATH')) exit;

// Hook into the original plugin's action with a higher priority (5) to run before the original handler
add_action('frm_trigger_aweber_action', 'custom_aweber_handler', 5, 3);

function custom_aweber_handler($action, $entry, $form) {
    // Debug: Log the action settings
    error_log('AWeber Action Settings: ' . print_r($action->post_content, true));
    
    // Get the AWeber account
    $account = FrmAwbrAppHelper::get_aweber_account();
    if (!$account) {
        return;
    }

    // Get the list ID from the action settings
    $list_id = $action->post_content['list_id'];
    if (empty($list_id)) {
        return;
    }

    // Get the list
    $lists = $account->lists;
    $list = null;
    foreach ($lists as $l) {
        if ($l->data['id'] == $list_id) {
            $list = $l;
            break;
        }
    }
    
    if (!$list) {
        return;
    }

    // Get the subscribers collection
    $subscribers = $list->subscribers;

    // Get the subscriber data
    $vars = array();
    if (isset($action->post_content['fields'])) {
        foreach ($action->post_content['fields'] as $field_key => $field_id) {
            if (empty($field_id)) {
                continue;
            }

            // Get the field value directly from the entry
            $value = isset($entry->metas[$field_id]) ? $entry->metas[$field_id] : '';
            if (empty($value)) {
                continue;
            }

            $vars[$field_key] = $value;
        }
    }

    // Debug: Log the vars array before adding tags
    error_log('AWeber Vars before tags: ' . print_r($vars, true));

    // Add tags if specified
    if (!empty($action->post_content['fields']['tags'])) {
        $vars['tags'] = array($action->post_content['fields']['tags']);
    }

    // Debug: Log the vars array after adding tags
    error_log('AWeber Vars after tags: ' . print_r($vars, true));

    // Check if we have an email field
    if (!isset($vars['email']) || empty($vars['email'])) {
        error_log('AWeber Error: No email field found in form submission. Available fields: ' . print_r(array_keys($vars), true));
        return;
    }

    try {
        // Try to find existing subscriber
        $existing_subscriber = null;
        try {
            $existing_subscriber = $subscribers->find(array('email' => $vars['email']));
        } catch (FrmAWeberAPIException $e) {
            // Subscriber not found, will create new one
        }

        if ($existing_subscriber) {
            // Get the first entry from the subscriber data
            $subscriber_entry = $existing_subscriber->data['entries'][0];
            
            // Get existing tags
            $existing_tags = array();
            if (isset($subscriber_entry['tags'])) {
                $existing_tags = is_string($subscriber_entry['tags']) 
                    ? explode(',', $subscriber_entry['tags']) 
                    : (array)$subscriber_entry['tags'];
            }
            
            $new_tags = isset($vars['tags']) ? $vars['tags'] : array();
            
            // Calculate which tags need to be added
            $tags_to_add = array_values(array_diff($new_tags, $existing_tags));
            
            // Only proceed with update if there are new tags to add
            if (!empty($tags_to_add)) {
                // Format tags according to AWeber API requirements
                $update_data = array(
                    'tags' => array(
                        'add' => $tags_to_add,
                        'remove' => array() // We don't want to remove any tags
                    )
                );
                
                // Update other fields if they've changed
                if (isset($vars['name']) && isset($subscriber_entry['name']) && $vars['name'] !== $subscriber_entry['name']) {
                    $update_data['name'] = $vars['name'];
                }
                if (isset($vars['custom_fields'])) {
                    $update_data['custom_fields'] = $vars['custom_fields'];
                }
                
                // Log the update data for debugging
                error_log('AWeber Update Debug: ' . print_r(array(
                    'existing_tags' => $existing_tags,
                    'new_tags' => $new_tags,
                    'tags_to_add' => $tags_to_add,
                    'update_data' => $update_data,
                    'subscriber_data' => $subscriber_entry,
                    'vars' => $vars
                ), true));
                
                // Update the subscriber using the adapter's request method
                try {
                    // Use the self_link from the subscriber data
                    $subscriber_url = $subscriber_entry['self_link'];
                    
                    $data = $existing_subscriber->adapter->request('PATCH', $subscriber_url, $update_data);
                    
                    // Log the response from AWeber
                    error_log('AWeber Update Response: ' . print_r(array(
                        'response_data' => $data,
                        'update_data' => $update_data,
                        'subscriber_url' => $subscriber_url
                    ), true));
                    
                    $subscriber = new FrmAWeberEntry($data, $subscriber_url, $existing_subscriber->adapter);
                } catch (Exception $e) {
                    // Log any errors during the update
                    error_log('AWeber Update Error: ' . print_r(array(
                        'error' => $e->getMessage(),
                        'update_data' => $update_data,
                        'subscriber_url' => $subscriber_url
                    ), true));
                    throw $e;
                }
            } else {
                // No new tags to add, just use the existing subscriber
                $subscriber = $existing_subscriber;
                error_log('AWeber Update: No new tags to add for subscriber ' . $vars['email']);
            }
        } else {
            // Create new subscriber if they don't exist
            $subscriber = $subscribers->create($vars);
        }

        // Only log UUID for successful updates
        if (isset($subscriber->data['uuid'])) {
            error_log('AWeber Subscription: ' . print_r(array(
                'uuid' => $subscriber->data['uuid'],
                'vars' => $vars
            ), true));
        }
    } catch (FrmAWeberAPIException $exception) {
        // Subscription failed.
        error_log('AWeber Exception: ' . print_r(array(
            'message' => $exception->message,
            'vars' => $vars
        ), true));
    }

    // Remove the original handler to prevent duplicate processing
    remove_action('frm_trigger_aweber_action', array('FrmAwbrAppController', 'trigger_aweber'), 10);
} 
